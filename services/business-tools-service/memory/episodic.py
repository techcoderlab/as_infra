# ─────────────────────────────────────────────────────
# Module   : Episodic Memory (Fact Extraction & Upsertion)
# Layer    : Application
# Pillar   : P1 Architecture, P2 Security, P9 Data Management
#
# UPDATED  : Fact extraction now routes through CloudflareStrategy
#            (OpenAI-compat endpoint) instead of calling Gemini directly.
#            - Uses output_format="json" → response_format json_object
#              (model must be in the strategy's JSON_MODE_SUPPORTED set)
#            - Buffered mode (use_stream=False); events drained synchronously
#            - Inherits circuit breaker + retries + model fallback from strategy
#            - Defensive JSON parsing (fences, prose, envelope normalization)
#            Sections 2 & 3 are pure PostgreSQL — unchanged.
# ─────────────────────────────────────────────────────

from __future__ import annotations

import json
import re
from typing import Optional

from core.config import settings
from core.logger import mcp_logger
from memory.db import get_pool
from services.strategies.factory import LLMStrategyFactory

# ─── Extraction config ────────────────────────────────────────────────────────

# Must match the key your LLMStrategyFactory registry uses for CloudflareStrategy
EXTRACTION_PROVIDER = "cloudflare"

# Short alias — resolved to "@cf/meta/llama-3.1-8b-instruct-fp8" by the strategy.
# This model is in JSON_MODE_SUPPORTED, so output_format="json" works.
# Use "llama-3.3-70b" for higher extraction quality at higher latency.
EXTRACTION_MODEL = "llama-3.1-8b"

EXTRACTION_SYSTEM_PROMPT = (
    "You extract durable facts about the USER from a conversation excerpt.\n"
    "Valid facts: the user's name, occupation, THEIR OWN business/role, location, budget, "
    "preferences, constraints, goals, and requirements.\n\n"
    "STRICT RULES:\n"
    "- Extract only what the USER explicitly stated in their own messages.\n"
    "- NEVER treat ASSISTANT statements as facts — the assistant may be wrong.\n"
    "- NEVER extract information about the ASSISTANT'S company (its services, owner, "
    "pricing, location) — that belongs to the knowledge base, not user memory.\n"
    "- The user's OWN business, budget, and needs ARE valid facts about the user.\n"
    "- A question is not a fact.\n\n"
    'Output ONLY a single JSON object: {"facts": [{"fact_type": "snake_case_category", '
    '"fact_value": "concise fact", "confidence": 0.0-1.0}]}\n'
    'If nothing extractable, return exactly: {"facts": []}\n\n'
    "Example:\n"
    "Conversation:\n"
    "USER: Hi, I'm Sana. I own a bakery in Lahore and can spend about PKR 100k/month on marketing.\n"
    "AI: Great to meet you Sana! We can definitely help with that.\n\n"
    'Correct output: {"facts": ['
    '{"fact_type": "user_name", "fact_value": "Sana", "confidence": 1.0}, '
    '{"fact_type": "business", "fact_value": "Owns a bakery in Lahore", "confidence": 0.95}, '
    '{"fact_type": "budget_limit", "fact_value": "~PKR 100k/month for marketing", "confidence": 0.9}]}'
)

def _get_cf_api_key(override: Optional[str]) -> str:
    """
    Resolves Cloudflare credentials ('<api_token>|<account_id>').
    Per-call override (tenant-specific integration) wins over platform default.
    Raises loudly — missing credentials are a deterministic config error.
    """
    api_key = override or getattr(settings, "CLOUDFLARE_API_KEY", None)
    if not api_key:
        raise ValueError(
            "Cloudflare credentials not configured for episodic memory. "
            "Set settings.CLOUDFLARE_API_KEY as '<api_token>|<account_id>' "
            "or pass api_key explicitly to extract_facts()."
        )
    return api_key


# ─────────────────────────────────────────────────────
# 1. Fact Extraction (LLM via CloudflareStrategy)
# ─────────────────────────────────────────────────────

def _build_extraction_prompt(conversation_text: str) -> str:
    return (
        "Extract episodic facts from the conversation below.\n\n"
        f"<conversation>\n{conversation_text}\n</conversation>\n\n"
        'Respond now with ONLY the JSON object: {"facts": [...]}'
    )


def _parse_facts_payload(response_text: str) -> list:
    """
    Parses model output into a raw list of fact dicts.
    Handles: bare arrays, {"facts": [...]}, markdown fences, surrounding prose,
    and double-encoded JSON (model returns the object as a string).
    """
    text = response_text.strip()

    # Strip markdown fences if the model added them despite instructions
    if text.startswith("```"):
        text = re.sub(r"^```[a-zA-Z]*\s*", "", text)
        text = re.sub(r"\s*```\s*$", "", text).strip()

    data = None                                   # ← explicit init: no UnboundLocalError possible

    try:
        data = json.loads(text)
    except json.JSONDecodeError:
        # Slice outermost JSON value out of surrounding prose
        starts = [i for i in (text.find("{"), text.find("[")) if i != -1]
        end = max(text.rfind("}"), text.rfind("]"))
        if not starts or end == -1 or min(starts) >= end:
            raise
        data = json.loads(text[min(starts):end + 1])

    # Double-encoding guard — MUST run AFTER `data` is assigned
    if isinstance(data, str):
        try:
            data = json.loads(data)
        except json.JSONDecodeError:
            return []

    # Normalize envelope
    if isinstance(data, dict):
        for key in ("facts", "data", "results"):
            if isinstance(data.get(key), list):
                return data[key]
        return []
    if isinstance(data, list):
        return data
    return []

def _validate_facts(raw_facts: list) -> list[dict]:
    by_type: dict[str, dict] = {}
    for fact in raw_facts:
        if not isinstance(fact, dict):
            continue
        fact_type  = str(fact.get("fact_type", "")).strip()
        fact_value = str(fact.get("fact_value", "")).strip()
        if not fact_type or not fact_value:
            continue
        try:
            confidence = max(0.0, min(1.0, float(fact.get("confidence", 1.0))))
        except (TypeError, ValueError):
            confidence = 1.0

        ft = fact_type.lower()
        existing = by_type.get(ft)
        # One active fact per type (matches upsert_fact's storage model);
        # ties → first wins (deterministic)
        if existing is None or confidence > existing["confidence"]:
            by_type[ft] = {"fact_type": fact_type, "fact_value": fact_value, "confidence": confidence}
    return list(by_type.values())


async def extract_facts(
    tenant_id: int,
    target_id: int,
    target_type: str,
    recent_turns: list[dict],
    api_key: Optional[str] = None,
) -> list[dict]:
    """
    Analyzes recent conversation turns using Cloudflare Workers AI (via
    CloudflareStrategy) to extract key episodic facts.

    Parameters:
        tenant_id:   The tenant's identifier (logging/security boundary).
        target_id:   The target's identifier.
        target_type: The target's type.
        recent_turns: e.g. [{"role": "user", "content": "..."},
                            {"role": "ai",   "content": "..."}]
        api_key:     Optional override '<cf_token>|<account_id>'.
                     Falls back to settings.CLOUDFLARE_API_KEY.

    Returns:
        list[dict]: [{"fact_type": ..., "fact_value": ..., "confidence": 0.0–1.0}]
                    Empty list on any extraction failure (non-fatal by design).

    Raises:
        ValueError: If tenant/target fields or credentials are missing.
    """
    if not tenant_id or not target_id or not target_type:
        raise ValueError("tenant_id, target_id, and target_type are required for fact extraction")
    
    if not recent_turns:
        return []


    # ── ANTI-POISONING GUARD ─────────────────────────────────────────────
    # Only the USER's words may become episodic facts. Assistant output is
    # unverified generation — letting it become "fact" creates a feedback
    # loop where the model cites its own claims as ground truth.
    user_turns = [t for t in recent_turns if str(t.get("role", "")).lower() in ("user", "human")]
    mcp_logger.info(
        f"[EpisodicMemory] user turns after filter: {len(user_turns)}/{len(recent_turns)}"
    )

    if not user_turns:
        mcp_logger.info("[EpisodicMemory] No user-authored turns — skipping extraction")
        return []

    conversation_text = "".join(
        f"{msg.get('role', 'unknown').upper()}: {msg.get('content', '')}\n"
        for msg in user_turns                      # ← user turns only
    )

    cf_api_key = _get_cf_api_key(api_key)
    strategy = LLMStrategyFactory.get_strategy(EXTRACTION_PROVIDER)

    chunks: list[str] = []
    error: Optional[str] = None

    try:
        async for event in strategy.execute(
            api_key            = cf_api_key,
            model              = EXTRACTION_MODEL,
            system_prompt      = EXTRACTION_SYSTEM_PROMPT,
            effective_history  = [],   # full conversation is embedded in the user message
            full_user_message  = _build_extraction_prompt(conversation_text),
            tools              = [],
            context            = {},
            output_format      = "json",   # triggers response_format on whitelisted models
            thinking_budget    = 0,
            use_stream         = False,    # buffered — we need the complete JSON at once
            max_iterations     = 1,        # no tools → single completion is enough
            temperature=0.0
        ):
            etype = event.get("type")
            if etype == "token":
                chunks.append(event.get("data") or "")
            elif etype == "error":
                error = event.get("data", "unknown error")
                break
            # tool_start / tool_end / done are not applicable here
    except Exception as e:
        error = f"{type(e).__name__}: {e}"

    if error:
        mcp_logger.error(
            f"[EpisodicMemory] CF extraction failed | tenant={tenant_id} "
            f"target_id={target_id} target_type={target_type} error={error}"
        )
        return []

    response_text = "".join(chunks)
    mcp_logger.info(
        f"[EpisodicMemory] raw extraction response | len={len(response_text)} "
        f"raw={response_text[:300]!r}"
    )
    if not response_text.strip():
        mcp_logger.warning("[EpisodicMemory] Model returned EMPTY response")
        return []

    try:
        raw_facts = _parse_facts_payload(response_text)
    except json.JSONDecodeError:
        mcp_logger.error(
            f"[EpisodicMemory] Failed to parse LLM JSON | tenant={tenant_id} "
            f"target_id={target_id} target_type={target_type} "
            f"raw={response_text[:200]}"
        )
        return []

    mcp_logger.info(f"[EpisodicMemory] parsed {len(raw_facts)} raw facts pre-validation")

    valid_facts = _validate_facts(raw_facts)
    mcp_logger.info(
        f"[EpisodicMemory] Extraction complete | tenant={tenant_id} "
        f"target_id={target_id} extracted={len(valid_facts)}"
    )
    return valid_facts


# ─────────────────────────────────────────────────────
# 2. Fact Upsertion (Versioning) — UNCHANGED
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
    Upserts a fact into episodic_facts with versioning:
    identical → "ignored", changed → old row superseded + new row → "superseded",
    new → "inserted".
    """
    if not tenant_id or not target_id or not target_type or not fact_type:
        raise ValueError("tenant_id, target_id, target_type, and fact_type are required")

    pool = await get_pool()
    target_id_str = str(target_id)

    async with pool.acquire() as conn:
        async with conn.transaction():
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
                if (active_fact["fact_value"] == fact_value
                        and abs(active_fact["confidence"] - confidence) < 0.01):
                    return "ignored"

                await conn.execute(
                    """
                    UPDATE episodic_facts
                    SET superseded_at = NOW(), updated_at = NOW()
                    WHERE tenant_id = $1 AND target_id = $2 AND target_type = $3
                    AND superseded_at IS NULL
                    AND fact_type IN ('company_services','services_offered','company_owner', 'company_location')
                    """,
                    tenant_id, target_id_str, target_type, fact_type
                )

            await conn.execute(
                """
                INSERT INTO episodic_facts
                    (tenant_id, target_id, target_type, fact_type, fact_value,
                     confidence, source_turn_id, created_at, updated_at)
                VALUES
                    ($1, $2, $3, $4, $5, $6, $7, NOW(), NOW())
                """,
                tenant_id, target_id_str, target_type, fact_type, fact_value,
                confidence, source_turn_id
            )

            if active_fact:
                mcp_logger.info(
                    f"[EpisodicMemory] Fact superseded | tenant={tenant_id} "
                    f"target_id={target_id} target_type={target_type} fact_type={fact_type}"
                )
                return "superseded"
            mcp_logger.info(
                f"[EpisodicMemory] Fact inserted | tenant={tenant_id} "
                f"target_id={target_id} target_type={target_type} fact_type={fact_type}"
            )
            return "inserted"


# ─────────────────────────────────────────────────────
# 3. Retrieval — UNCHANGED
# ─────────────────────────────────────────────────────

async def get_active_facts(tenant_id: int, target_id: int, target_type: str) -> list[dict]:
    """Retrieves all currently active (non-superseded) facts."""
    if not tenant_id or not target_id or not target_type:
        raise ValueError("tenant_id, target_id, and target_type are required")

    pool = await get_pool()
    target_id_str = str(target_id)

    async with pool.acquire() as conn:
        rows = await conn.fetch(
            """
            SELECT id, fact_type, fact_value, confidence, source_turn_id, created_at
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