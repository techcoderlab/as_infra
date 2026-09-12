# ─────────────────────────────────────────────────────
# Module   : Memory Router
# Layer    : Presentation
# Pillar   : P1 Architecture, P2 Security
# ─────────────────────────────────────────────────────

from fastapi import APIRouter, HTTPException, BackgroundTasks
from pydantic import BaseModel, Field
from typing import Optional, List, Dict, Any

from core.logger import mcp_logger
from memory.episodic import extract_facts, upsert_fact

router = APIRouter(
    prefix="/v1/memory",
    tags=["Memory"]
)

# ─────────────────────────────────────────────────────
# DTOs (Data Transfer Objects)
# ─────────────────────────────────────────────────────

class TurnDTO(BaseModel):
    role: str = Field(..., description="Role of the sender (e.g., 'user', 'ai')")
    content: str = Field(..., description="Message content text")

class ExtractFactsRequest(BaseModel):
    tenant_id: int = Field(..., description="The ID of the tenant")
    lead_id: int = Field(..., description="The ID of the lead")
    recent_turns: List[TurnDTO] = Field(..., description="The recent conversation turns to extract facts from")
    source_turn_id: Optional[str] = Field(None, description="Optional ID of the chat message that triggered this")

class ExtractFactsResponse(BaseModel):
    success: bool
    inserted: int
    superseded: int
    ignored: int


class SummarizeRequest(BaseModel):
    tenant_id: int = Field(..., description="The ID of the tenant")
    conversation_id: int = Field(..., description="The ID of the conversation (ai_chat_id)")
    lead_id: int = Field(..., description="The ID of the lead")

class SummarizeResponse(BaseModel):
    success: bool
    summary_text: Optional[str]


# ─────────────────────────────────────────────────────
class IngestRequest(BaseModel):
    tenant_id: int
    source_id: int
    source_type: str
    source_ref: Optional[str] = None
    content: Optional[str] = None

class IngestResponse(BaseModel):
    success: bool
    chunks: int
    message: str

# ─────────────────────────────────────────────────────
# Endpoints
# ─────────────────────────────────────────────────────

import httpx
import tempfile
import os

async def _extract_text(url: str) -> str:
    """Downloads a file and extracts text based on extension."""
    if not url:
        return ""

    # ─────────────────────────────────────────────────────
    # FIX: Ngrok 307 Redirect & Warning Bypass
    # ─────────────────────────────────────────────────────
    safe_url = url
    if "ngrok-free.dev" in safe_url and safe_url.startswith("http://"):
        safe_url = safe_url.replace("http://", "https://")

    headers = {
        "ngrok-skip-browser-warning": "true",
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AI-Agent/1.0"
    }
    # ─────────────────────────────────────────────────────
        
    mcp_logger.info(f"[SemanticMemory] Downloading file for extraction: {safe_url}")
    try:
        # Added follow_redirects=True to handle HTTP 307 correctly
        async with httpx.AsyncClient(timeout=30.0, follow_redirects=True) as client:
            response = await client.get(safe_url, headers=headers)
            response.raise_for_status()
    except httpx.TimeoutException as e:
        mcp_logger.error(f"[SemanticMemory] Timeout downloading file {safe_url}: {e}")
        raise ValueError(f"Download timed out: {safe_url}")
    except httpx.HTTPStatusError as e:
        mcp_logger.error(f"[SemanticMemory] HTTP Error downloading file {safe_url}: {e.response.status_code}")
        raise ValueError(f"HTTP Error {e.response.status_code} downloading file: {safe_url}")
    except Exception as e:
        mcp_logger.error(f"[SemanticMemory] Unexpected error downloading file {safe_url}: {e}")
        raise ValueError(f"Failed to download file {safe_url}: {e}")
        
        
    ext = safe_url.split('.')[-1].lower() if '.' in safe_url else 'txt'
    
    if 'pdf' in ext or 'pdf' in response.headers.get('content-type', '').lower():
        import pypdf
        import io
        pdf_reader = pypdf.PdfReader(io.BytesIO(response.content))
        return "\n".join(page.extract_text() for page in pdf_reader.pages if page.extract_text())
        
    elif 'doc' in ext or 'docx' in ext or 'word' in response.headers.get('content-type', '').lower():
        import docx
        import io
        doc = docx.Document(io.BytesIO(response.content))
        return "\n".join(paragraph.text for paragraph in doc.paragraphs)
        
    else:
        # Fallback to plain text
        return response.content.decode('utf-8', errors='ignore')


@router.post("/ingest", response_model=IngestResponse)
async def ingest_endpoint(request: IngestRequest, background_tasks: BackgroundTasks):
    from memory.semantic import ingest_document
    
    try:
        text = request.content or ""
        
        # If no content but we have a URL, extract from URL
        if not text and request.source_ref and request.source_ref.startswith('http'):
            try:
                text = await _extract_text(request.source_ref)
            except Exception as e:
                mcp_logger.error(f"[SemanticMemory] Failed to extract text from {request.source_ref}: {e}")
                raise HTTPException(status_code=400, detail=f"Could not extract text from document: {e}")
                
        if not text:
            raise HTTPException(status_code=400, detail="No content or extractable URL provided")
            
        chunks_inserted = await ingest_document(
            tenant_id=request.tenant_id,
            text=text,
            source_type=request.source_type,
            source_ref=request.source_ref,
            source_id=request.source_id
        )
        
        return IngestResponse(success=True, chunks=chunks_inserted, message=f"Successfully ingested {chunks_inserted} chunks")
        
    except HTTPException:
        raise
    except Exception as e:
        mcp_logger.error(f"[SemanticMemory] Ingestion failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.delete("/sources/{source_id}")
async def delete_source_endpoint(source_id: int):
    from memory.db import get_pool
    try:
        pool = await get_pool()
        async with pool.acquire() as conn:
            await conn.execute("DELETE FROM tenant_vector_memories WHERE source_id = $1", source_id)
        return {"success": True, "message": "Source deleted"}
    except Exception as e:
        mcp_logger.error(f"[SemanticMemory] Deletion failed: {e}")
        raise HTTPException(status_code=500, detail=str(e))


@router.post("/extract-facts", response_model=ExtractFactsResponse)
async def extract_facts_endpoint(request: ExtractFactsRequest):
    """
    Extracts facts from recent chat turns and upserts them into episodic memory.
    """
    try:
        mcp_logger.info(f"[MemoryRouter] Extract facts request | tenant={request.tenant_id} lead={request.lead_id}")
        
        # 1. Extract facts using LLM
        turns = [turn.model_dump() for turn in request.recent_turns]
        facts = await extract_facts(
            tenant_id=request.tenant_id,
            lead_id=request.lead_id,
            recent_turns=turns
        )
        
        if not facts:
            return ExtractFactsResponse(success=True, inserted=0, superseded=0, ignored=0)
            
        # 2. Upsert facts to DB
        inserted_count = 0
        superseded_count = 0
        ignored_count = 0
        
        for fact in facts:
            result = await upsert_fact(
                tenant_id=request.tenant_id,
                lead_id=request.lead_id,
                fact_type=fact["fact_type"],
                fact_value=fact["fact_value"],
                confidence=fact.get("confidence", 1.0),
                source_turn_id=request.source_turn_id
            )
            
            if result == "inserted":
                inserted_count += 1
            elif result == "superseded":
                superseded_count += 1
            else:
                ignored_count += 1
                
        mcp_logger.info(f"[MemoryRouter] Fact extraction complete | tenant={request.tenant_id} inserted={inserted_count} superseded={superseded_count}")
        
        return ExtractFactsResponse(
            success=True,
            inserted=inserted_count,
            superseded=superseded_count,
            ignored=ignored_count
        )
        
    except Exception as e:
        mcp_logger.error(f"[MemoryRouter] Failed to extract facts: {str(e)}")
        raise HTTPException(status_code=500, detail="Internal server error during fact extraction")


@router.post("/summarize", response_model=SummarizeResponse)
async def summarize_endpoint(request: SummarizeRequest):
    """
    Summarizes the recent conversation turns, integrating with episodic memory.
    Must be called AFTER /extract-facts.
    """
    try:
        from memory.working import summarize_conversation
        mcp_logger.info(f"[MemoryRouter] Summarize request | tenant={request.tenant_id} conv={request.conversation_id}")
        
        summary_text = await summarize_conversation(
            tenant_id=request.tenant_id,
            conversation_id=request.conversation_id,
            lead_id=request.lead_id
        )
        
        return SummarizeResponse(
            success=True,
            summary_text=summary_text
        )
        
    except Exception as e:
        mcp_logger.error(f"[MemoryRouter] Failed to summarize: {str(e)}")
        raise HTTPException(status_code=500, detail="Internal server error during summarization")