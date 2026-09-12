# ─────────────────────────────────────────────────────
# Module   : Semantic Memory (Chunking, Embedding, Ingestion, Search)
# Layer    : Application
# Pillar   : P1 Architecture, P2 Security, P4 Performance
# ─────────────────────────────────────────────────────

from __future__ import annotations

import re
import io
from typing import Optional

from core.config import settings
from core.logger import mcp_logger
from memory.db import get_pool

# ─────────────────────────────────────────────────────
# Embedding Model Singleton
# ─────────────────────────────────────────────────────
# TRADE-OFF: P5 Scalability — loading the model in-process means each
# Gunicorn worker holds its own copy (~200MB). For the current 2-worker
# deployment this is acceptable. If worker count grows, move to a
# dedicated embedding micro-service.

_model = None


def _get_model():
    """
    Returns the fastembed model as a process-level singleton.
    Loaded lazily on first call to avoid startup delay when the tool is
    never invoked.

    Returns:
        TextEmbedding: The loaded embedding model.
    """
    global _model
    if _model is None:
        from fastembed import TextEmbedding

        mcp_logger.info(f"[SemanticMemory] Loading embedding model: {settings.EMBEDDING_MODEL}")
        _model = TextEmbedding(model_name=settings.EMBEDDING_MODEL)
        mcp_logger.info("[SemanticMemory] Model loaded successfully")
    return _model


# ─────────────────────────────────────────────────────
# 1. Document Chunking
# ─────────────────────────────────────────────────────

def chunk_text(
    text: str,
    max_tokens: int = 400,
    overlap_tokens: int = 50,
) -> list[str]:
    """
    Splits text into overlapping chunks of approximately max_tokens size.

    Uses sentence-boundary splitting to avoid cutting mid-sentence, with a
    rough word-based token estimator (1 word ≈ 1.33 tokens).

    Parameters:
        text: The source text to chunk.
        max_tokens: Target maximum tokens per chunk (300-500 range).
        overlap_tokens: Number of overlapping tokens between consecutive chunks.

    Returns:
        list[str]: Non-empty text chunks.

    Side effects:
        None — pure function.
    """
    # O(n) time, O(n) space where n = len(text)
    if not text or not text.strip():
        return []

    # Split on sentence boundaries (period, question mark, exclamation, newline)
    sentences = re.split(r'(?<=[.!?])\s+|\n+', text.strip())
    sentences = [s.strip() for s in sentences if s.strip()]

    if not sentences:
        return []

    chunks: list[str] = []
    current_words: list[str] = []
    current_count = 0

    # Rough token estimate: 1 word ≈ 1.33 tokens
    # This avoids a tiktoken dependency for a simple chunking use case
    def _estimate_tokens(words: list[str]) -> int:
        return int(len(words) * 1.33)

    for sentence in sentences:
        sentence_words = sentence.split()
        sentence_token_est = _estimate_tokens(sentence_words)

        # If adding this sentence would exceed the limit, finalize current chunk
        if current_count > 0 and current_count + sentence_token_est > max_tokens:
            chunks.append(" ".join(current_words))

            # Calculate overlap: keep the last N words as overlap
            overlap_word_count = int(overlap_tokens / 1.33)
            if overlap_word_count > 0 and len(current_words) > overlap_word_count:
                current_words = current_words[-overlap_word_count:]
                current_count = _estimate_tokens(current_words)
            else:
                current_words = []
                current_count = 0

        current_words.extend(sentence_words)
        current_count = _estimate_tokens(current_words)

    # Flush remaining words as the last chunk
    if current_words:
        chunks.append(" ".join(current_words))

    return chunks


# ─────────────────────────────────────────────────────
# 2. Embedding Helper
# ─────────────────────────────────────────────────────

def get_embeddings(texts: list[str]) -> list[list[float]]:
    """
    Encodes a batch of text strings into 384-dimensional float vectors
    using the BAAI/bge-small-en-v1.5 model on CPU via fastembed.

    Parameters:
        texts: List of text strings to embed.

    Returns:
        list[list[float]]: One 384-dim vector per input text.

    Side effects:
        Loads the model singleton on first call.
    """
    if not texts:
        return []

    model = _get_model()
    # fastembed returns a generator of numpy arrays
    embeddings = list(model.embed(texts))
    return [emb.tolist() for emb in embeddings]


# ─────────────────────────────────────────────────────
# 3. Master Ingestion Helper (URL / File Parsing)
# ─────────────────────────────────────────────────────

async def ingest_knowledge_source(
    tenant_id: int,
    source_id: int,
    source_type: str,
    source_ref: str,
    text: Optional[str] = None,
    source_url: Optional[str] = None
) -> int:
    """
    Downloads file from URL if provided (handling ngrok/redirects), 
    parses PDF/DOCX/TXT, and then delegates to ingest_document.
    """
    import httpx

    content_text = text or ""

    if source_url:
        # Ngrok 307 Redirect & HTTPS Fix
        safe_url = source_url
        if "ngrok-free.dev" in safe_url and safe_url.startswith("http://"):
            safe_url = safe_url.replace("http://", "https://")

        # Ngrok Warning Bypass Headers
        headers = {
            "ngrok-skip-browser-warning": "true",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AI-Agent/1.0"
        }

        try:
            mcp_logger.info(f"[SemanticMemory] Downloading file from {safe_url}")
            
            # follow_redirects=True fixes the HTTP 307 error
            async with httpx.AsyncClient(follow_redirects=True) as client:
                response = await client.get(safe_url, headers=headers, timeout=30.0)
                response.raise_for_status()
                raw_bytes = response.content

            lower_url = safe_url.lower()
            
            # Parse PDF
            if lower_url.endswith(".pdf"):
                import pypdf
                reader = pypdf.PdfReader(io.BytesIO(raw_bytes))
                extracted = []
                for page in reader.pages:
                    page_text = page.extract_text()
                    if page_text:
                        extracted.append(page_text)
                content_text = "\n".join(extracted)

            # Parse DOCX
            elif lower_url.endswith(".docx"):
                import docx
                doc = docx.Document(io.BytesIO(raw_bytes))
                content_text = "\n".join([p.text for p in doc.paragraphs])

            # Fallback for plain text (.txt, .md)
            else:
                content_text = raw_bytes.decode('utf-8', errors='ignore')

        except Exception as e:
            mcp_logger.error(f"[SemanticMemory] Download/Parse failed for {safe_url}: {str(e)}")
            raise ValueError(f"Could not extract text from document: {str(e)}")

    if not content_text.strip():
        raise ValueError("Could not extract any readable text from the document.")

    # Proceed to chunk and database ingestion
    return await ingest_document(
        tenant_id=tenant_id,
        text=content_text,
        source_type=source_type,
        source_ref=source_ref,
        source_id=source_id
    )


# ─────────────────────────────────────────────────────
# 4. Core Database Ingestion Helper
# ─────────────────────────────────────────────────────

async def ingest_document(
    tenant_id: int,
    text: str,
    source_type: str = "document",
    source_ref: str | None = None,
    source_id: int | None = None,
) -> int:
    """
    Chunks text, generates embeddings, and batch-inserts into tenant_vector_memories.

    Parameters:
        tenant_id: Mandatory tenant scope — every row is tagged.
        text: Raw text content to ingest.
        source_type: Origin category ('document', 'conversation', 'manual').
        source_ref: Identifier for the source (filename, chat_id, etc.).
        source_id: Identifier of the Knowledge Source record.

    Returns:
        int: Number of chunks ingested.

    Raises:
        ValueError: If tenant_id is missing or text is empty.
        asyncpg.PostgresError: On database failures.
    """
    # P2 Security: tenant_id is mandatory — zero-trust boundary
    if not tenant_id:
        raise ValueError("tenant_id is required for memory ingestion — zero-trust policy")

    if not text or not text.strip():
        mcp_logger.warning(f"[SemanticMemory] Skipping ingestion: empty text for tenant={tenant_id}")
        return 0

    # Step 1: Chunk
    chunks = chunk_text(text)
    if not chunks:
        return 0

    # Step 2: Embed (CPU-bound but batched for efficiency)
    embeddings = get_embeddings(chunks)

    # Step 3: Batch insert
    pool = await get_pool()

    # P4 Performance: use executemany for batch insert instead of N individual inserts
    # O(n) where n = number of chunks
    rows = [
        (tenant_id, source_id, chunk, _format_vector(emb), source_type, source_ref)
        for chunk, emb in zip(chunks, embeddings)
    ]

    async with pool.acquire() as conn:
        await conn.executemany(
            """
            INSERT INTO tenant_vector_memories
                (tenant_id, source_id, chunk_text, embedding, source_type, source_ref, created_at, updated_at)
            VALUES
                ($1, $2, $3, $4::vector, $5, $6, NOW(), NOW())
            """,
            rows,
        )

    mcp_logger.info(
        f"[SemanticMemory] Ingested {len(rows)} chunks | "
        f"tenant={tenant_id} source_id={source_id} source={source_type}:{source_ref}"
    )
    return len(rows)


# ─────────────────────────────────────────────────────
# 5. Search Helper
# ─────────────────────────────────────────────────────

async def search_memories(
    tenant_id: int,
    query: str,
    active_source_ids: list[int],
    top_k: int = 5,
) -> str:
    """
    Embeds the query and performs a cosine-distance search against
    tenant_vector_memories, strictly filtered by tenant_id.

    Returns only memories belonging to the specified active_source_ids.

    Parameters:
        tenant_id: Mandatory tenant scope for the search.
        query: Natural-language query to embed and search.
        active_source_ids: List of active knowledge source IDs to search within.
        top_k: Maximum number of results to return.

    Returns:
        str: Newline-separated relevant text chunks, or empty string
             if nothing passes the relevance threshold.

    Raises:
        ValueError: If tenant_id is missing.
    """
    # P2 Security: tenant_id is mandatory — zero-trust boundary
    if not tenant_id:
        raise ValueError("tenant_id is required for memory search — zero-trust policy")

    if not query or not query.strip():
        return ""

    # Step 1: Embed the query
    query_embedding = get_embeddings([query])[0]
    query_vector_str = _format_vector(query_embedding)

    # Step 2: Build the SQL with strict tenant isolation
    # Cosine distance operator <=> returns values in [0, 2] range
    # 0 = identical, 2 = opposite. Threshold filters irrelevant results.
    threshold = settings.MEMORY_RELEVANCE_THRESHOLD

    pool = await get_pool()

    async with pool.acquire() as conn:
        if active_source_ids:
            rows = await conn.fetch(
                """
                SELECT chunk_text, (embedding <=> $1::vector) AS distance
                FROM tenant_vector_memories
                WHERE tenant_id = $2
                  AND source_id = ANY($3::int[])
                  AND (embedding <=> $1::vector) < $4
                ORDER BY distance ASC
                LIMIT $5
                """,
                query_vector_str,
                tenant_id,
                active_source_ids,
                threshold,
                top_k,
            )
        else:
            rows = []

    if not rows:
        mcp_logger.info(f"[SemanticMemory] No relevant memories found | tenant={tenant_id} query={query[:50]}")
        return ""

    # Format results as newline-separated chunks
    results = [row["chunk_text"] for row in rows]
    mcp_logger.info(
        f"[SemanticMemory] Found {len(results)} relevant memories | "
        f"tenant={tenant_id} top_distance={rows[0]['distance']:.4f}"
    )
    return "\n---\n".join(results)


# ─────────────────────────────────────────────────────
# Internal Helpers
# ─────────────────────────────────────────────────────

def _format_vector(embedding: list[float]) -> str:
    """
    Formats a Python list of floats into pgvector's text representation.

    Parameters:
        embedding: A list of float values.

    Returns:
        str: pgvector text format, e.g. '[0.1,0.2,0.3]'.
    """
    return "[" + ",".join(str(float(v)) for v in embedding) + "]"