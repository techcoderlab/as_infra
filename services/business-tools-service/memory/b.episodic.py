# ─────────────────────────────────────────────────────
# Module   : Episodic Memory (Fact Extraction & Upsertion)
# Layer    : Application
# Pillar   : P1 Architecture, P2 Security, P9 Data Management
# ─────────────────────────────────────────────────────

from services.strategies.factory import LLMStrategyFactory
import json
from typing import Optional

from google import genai
from google.genai import types

from core.config import settings
from core.logger import mcp_logger
from memory.db import get_pool
from services.llm import get_gemini_client


# ─────────────────────────────────────────────────────
# 1. Fact Extraction (LLM)
# ─────────────────────────────────────────────────────

async def extract_facts(tenant_id: int, target_id: int, target_type: str, recent_turns: list[dict]) -> list[dict]:
    """
    Analyzes recent conversation turns using Gemini to extract key episodic facts
    (e.g., user preferences, stated constraints, important life events).
    
    Parameters:
        tenant_id: The tenant's identifier (used for logging/security boundary).
        target_id: The target's identifier.
        target_type: The target's type.
        recent_turns: A list of dicts representing recent messages, e.g.,
                      [{"role": "user", "content": "..."}, {"role": "ai", "content": "..."}]
                      
    Returns:
        list[dict]: A list of extracted facts in the format:
                    [{"fact_type": "...", "fact_value": "...", "confidence": 1.0}]
                    
    Raises:
        ValueError: If tenant_id, target_id, and target_type are missing.
    """
    if not tenant_id or not target_id or not target_type:
        raise ValueError("tenant_id, target_id, and target_type are required for fact extraction")
        
    if not recent_turns:
        return []

    mcp_logger.info(f"[EpisodicMemory] Extracting facts | tenant={tenant_id} target_id={target_id} target_type={target_type} turns={len(recent_turns)}")

    # Format the conversation history into a single text block for the prompt
    conversation_text = ""
    for msg in recent_turns:
        role = msg.get("role", "unknown").upper()
        content = msg.get("content", "")
        conversation_text += f"{role}: {content}\n"

    system_prompt = (
        "You are a factual memory extraction assistant. Analyze the following conversation excerpt "
        "and extract any permanent or semi-permanent facts about the user (e.g., name, preferences, "
        "budget, constraints, important events, specific requirements). \n\n"
        "Return the output STRICTLY as a JSON array of objects. Each object must have:\n"
        '- "fact_type": A short, snake_case string categorizing the fact (e.g., "budget_limit", "dietary_preference").\n'
        '- "fact_value": The actual value or description of the fact.\n'
        '- "confidence": A float between 0.0 and 1.0 indicating how certain you are of this fact based purely on the text.\n\n'
        "If there are no facts to extract, return an empty array: []\n\n"
        "Conversation:\n"
        f"{conversation_text}"
    )
    
    client = get_gemini_client(settings.GEMINI_API_KEY)
    
    # Configure Gemini for strict JSON output
    config = types.GenerateContentConfig(
        response_mime_type="application/json",
        temperature=0.0  # Lowest temperature for consistent fact extraction
    )

    try:
        # P1 Architecture: Using a fast text model for extraction instead of the embedding model
        # Defaulting to gemini-2.5-flash as it's the standard for fast/cheap extraction tasks
        model_name = "gemini-2.5-flash"
        
        response = await client.aio.models.generate_content(
            model=model_name,
            contents=system_prompt,
            config=config
        )
        
        response_text = response.text
        if not response_text:
            return []
            
        facts = json.loads(response_text)
        if not isinstance(facts, list):
            mcp_logger.warning(f"[EpisodicMemory] LLM returned non-list JSON | tenant={tenant_id} target_id={target_id} target_type={target_type}")
            return []
            
        # Filter and validate fields
        valid_facts = []
        for fact in facts:
            if isinstance(fact, dict) and "fact_type" in fact and "fact_value" in fact:
                valid_facts.append({
                    "fact_type": str(fact["fact_type"]),
                    "fact_value": str(fact["fact_value"]),
                    "confidence": float(fact.get("confidence", 1.0))
                })
                
        return valid_facts
        
    except json.JSONDecodeError:
        mcp_logger.error(f"[EpisodicMemory] Failed to parse LLM JSON | tenant={tenant_id} target_id={target_id} target_type={target_type}")
        return []
    except Exception as e:
        mcp_logger.error(f"[EpisodicMemory] LLM extraction failed | tenant={tenant_id} target_id={target_id} target_type={target_type} error={str(e)}")
        return []


# ─────────────────────────────────────────────────────
# 2. Fact Upsertion (Versioning)
# ─────────────────────────────────────────────────────

async def upsert_fact(
    tenant_id: int,
    target_id: int,
    target_type: str,
    fact_type: str,
    fact_value: str,
    confidence: float = 1.0,
    source_turn_id: Optional[str] = None
) -> str:
    """
    Upserts a fact into the episodic_facts table.
    Implements a versioning strategy: if an active fact of the same type exists
    and its value or confidence differs, it is marked as superseded, and a new
    record is inserted. If it's identical, no action is taken.
    
    Parameters:
        tenant_id: Mandatory tenant scope.
        target_id: Mandatory target scope.
        target_type: Mandatory target type.
        fact_type: Category of the fact.
        fact_value: The fact text.
        confidence: Certainty level (0.0 to 1.0).
        source_turn_id: Optional reference to the chat message ID.
        
    Returns:
        str: "inserted", "superseded", or "ignored"
    """
    if not tenant_id or not target_id or not target_type or not fact_type:
        raise ValueError("tenant_id, target_id, target_type, and fact_type are required")

    pool = await get_pool()

    # FORCE STRING CONVERSION HERE
    target_id_str = str(target_id)
    
    async with pool.acquire() as conn:
        # P9 Data Management: Run the lookup and conditional insert in a transaction
        async with conn.transaction():
            # 1. Lookup active fact
            active_fact = await conn.fetchrow(
                """
                SELECT id, fact_value, confidence 
                FROM episodic_facts
                WHERE tenant_id = $1 
                  AND target_id = $2 
                  AND target_type = $3
                  AND fact_type = $4 
                  AND superseded_at IS NULL
                FOR UPDATE
                """,
                tenant_id, target_id_str, target_type, fact_type
            )
            
            if active_fact:
                # 2. Check if identical
                if active_fact["fact_value"] == fact_value and abs(active_fact["confidence"] - confidence) < 0.01:
                    return "ignored"
                    
                # 3. Supersede old fact
                await conn.execute(
                    """
                    UPDATE episodic_facts 
                    SET superseded_at = NOW(), updated_at = NOW()
                    WHERE id = $1
                    """,
                    active_fact["id"]
                )
                
            # 4. Insert new fact
            await conn.execute(
                """
                INSERT INTO episodic_facts 
                    (tenant_id, target_id, target_type, fact_type, fact_value, confidence, source_turn_id, created_at, updated_at)
                VALUES 
                    ($1, $2, $3, $4, $5, $6, $7, NOW(), NOW())
                """,
                tenant_id, target_id_str, target_type, fact_type, fact_value, confidence, source_turn_id
            )
            
            if active_fact:
                mcp_logger.info(f"[EpisodicMemory] Fact superseded | tenant={tenant_id} target_id={target_id} target_type={target_type} fact_type={fact_type}")
                return "superseded"
            else:
                mcp_logger.info(f"[EpisodicMemory] Fact inserted | tenant={tenant_id} target_id={target_id} target_type={target_type} fact_type={fact_type}")
                return "inserted"


# ─────────────────────────────────────────────────────
# 3. Retrieval
# ─────────────────────────────────────────────────────

async def get_active_facts(tenant_id: int, target_id: int, target_type: str) -> list[dict]:
    """
    Retrieves all currently active (non-superseded) facts for a given lead.
    
    Parameters:
        tenant_id: Mandatory tenant scope.
        target_id: Mandatory target scope.
        target_type: Mandatory target type.
        
    Returns:
        list[dict]: Active facts.
    """
    if not tenant_id or not target_id or not target_type:
        raise ValueError("tenant_id, target_id, and target_type are required")
        
    pool = await get_pool()
    
    # FORCE STRING CONVERSION HERE
    target_id_str = str(target_id)

    async with pool.acquire() as conn:
        rows = await conn.fetch(
            """
            SELECT fact_type, fact_value, confidence, source_turn_id, created_at
            FROM episodic_facts
            WHERE tenant_id = $1 
              AND target_id = $2 
              AND target_type = $3
              AND superseded_at IS NULL
            ORDER BY created_at ASC
            """,
            tenant_id, target_id_str, target_type
        )
        
    return [dict(row) for row in rows]
