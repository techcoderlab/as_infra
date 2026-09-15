# ─────────────────────────────────────────────────────
# Module   : Working Memory
# Layer    : Application
# Pillar   : P1 Architecture, P2 Security, P9 Data Management
# ─────────────────────────────────────────────────────

from typing import Optional
from google import genai
from google.genai import types

from core.config import settings
from core.logger import mcp_logger
from memory.db import get_pool
from memory.episodic import get_active_facts
from services.llm import get_gemini_client


async def get_raw_chat_turns(tenant_id: int, conversation_id: int, limit: int = 6) -> list[dict]:
    """
    Retrieves the most recent raw chat turns for a conversation, strictly isolated by tenant.
    
    Parameters:
        tenant_id: Mandatory tenant scope.
        conversation_id: Maps to ai_chat_id in chat_messages.
        limit: Number of recent turns to retrieve.
        
    Returns:
        List of dicts representing chat turns, ordered chronologically (oldest to newest in the limited set).
    """
    if not tenant_id or not conversation_id:
        raise ValueError("tenant_id and conversation_id are required")
        
    pool = await get_pool()
    
    async with pool.acquire() as conn:
        # P2 Security: Join with ai_chats to enforce tenant_id boundary
        rows = await conn.fetch(
            """
            SELECT cm.id, cm.role, cm.content, cm.created_at
            FROM chat_messages cm
            JOIN ai_chats ac ON cm.ai_chat_id = ac.id
            WHERE ac.tenant_id = $1 AND cm.ai_chat_id = $2
            ORDER BY cm.id DESC
            LIMIT $3
            """,
            tenant_id, conversation_id, limit
        )
        
    # The query returns newest first (DESC). We want chronological order (ASC).
    turns = [dict(r) for r in rows]
    turns.reverse()
    
    return turns


async def summarize_conversation(tenant_id: int, conversation_id: int, target_id: int, target_type: str) -> Optional[str]:
    """
    Generates a progressive summary of the conversation up to the current turn, 
    avoiding restatement of permanent facts stored in episodic memory.
    
    Parameters:
        tenant_id: Mandatory tenant scope.
        conversation_id: Mandatory conversation scope.
        target_id: Mandatory target scope (for fetching episodic facts).
        target_type: Mandatory target type (for fetching episodic facts).
        
    Returns:
        The generated summary text, or None if no new turns to summarize.
    """
    if not tenant_id or not conversation_id or not target_id or not target_type:
        raise ValueError("tenant_id, conversation_id, and target_id and target_type are required")

    pool = await get_pool()
    
    async with pool.acquire() as conn:
        # 1. Get the latest summary and the turn ID it covers up to
        latest_summary = await conn.fetchrow(
            """
            SELECT summary_text, covers_up_to_turn
            FROM chat_summaries
            WHERE tenant_id = $1 AND conversation_id = $2
            ORDER BY id DESC LIMIT 1
            """,
            tenant_id, conversation_id
        )
        
        last_covered_id = latest_summary["covers_up_to_turn"] if latest_summary else 0
        
        # 2. Fetch all raw turns since the last summary
        new_turns = await conn.fetch(
            """
            SELECT cm.id, cm.role, cm.content
            FROM chat_messages cm
            JOIN ai_chats ac ON cm.ai_chat_id = ac.id
            WHERE ac.tenant_id = $1 AND cm.ai_chat_id = $2 AND cm.id > $3
            ORDER BY cm.id ASC
            """,
            tenant_id, conversation_id, last_covered_id
        )
        
        if not new_turns:
            mcp_logger.info(f"[WorkingMemory] No new turns to summarize | tenant={tenant_id} conv={conversation_id}")
            return None
            
        newest_turn_id = new_turns[-1]["id"]
        
    # 3. Fetch active episodic facts for this lead
    facts = await get_active_facts(tenant_id, target_id, target_type)
    
    # 4. Formulate the LLM prompt
    previous_summary_text = latest_summary["summary_text"] if latest_summary else "No previous summary."
    
    facts_text = ""
    if facts:
        facts_text = "KNOWN EPISODIC FACTS:\n"
        for f in facts:
            facts_text += f"- {f['fact_type']}: {f['fact_value']}\n"
    else:
        facts_text = "No known episodic facts."
        
    conversation_text = ""
    for turn in new_turns:
        role = turn["role"].upper()
        content = turn["content"]
        conversation_text += f"{role}: {content}\n"
        
    system_prompt = (
        "You are an AI tasked with maintaining a rolling summary of a conversation. "
        "Your goal is to succinctly summarize the LATEST messages while integrating the PREVIOUS summary. \n\n"
        "CRITICAL INSTRUCTION: Do NOT restate facts that are already present in the episodic facts list. "
        "If the conversation mentions something already known as a fact, skip it in the summary or just briefly refer to it by its type (e.g., 'discussed budget constraints'). "
        "Focus on the *flow*, *intent*, and *unresolved context* of the new messages.\n\n"
        f"PREVIOUS SUMMARY:\n{previous_summary_text}\n\n"
        f"{facts_text}\n\n"
        "NEW MESSAGES TO SUMMARIZE:\n"
        f"{conversation_text}\n\n"
        "Output ONLY the new progressive summary text."
    )
    
    client = get_gemini_client(settings.GEMINI_API_KEY)
    
    try:
        model_name = "gemini-2.5-flash"
        
        response = await client.aio.models.generate_content(
            model=model_name,
            contents=system_prompt,
            config=types.GenerateContentConfig(temperature=0.0)
        )
        
        new_summary_text = response.text.strip() if response.text else ""
        
        if not new_summary_text:
            return None
            
        # 5. Save the new summary
        async with pool.acquire() as conn:
            await conn.execute(
                """
                INSERT INTO chat_summaries (tenant_id, conversation_id, summary_text, covers_up_to_turn, created_at)
                VALUES ($1, $2, $3, $4, NOW())
                """,
                tenant_id, conversation_id, new_summary_text, newest_turn_id
            )
            
        mcp_logger.info(f"[WorkingMemory] Summarization completed | tenant={tenant_id} conv={conversation_id} up_to={newest_turn_id}")
        return new_summary_text
        
    except Exception as e:
        mcp_logger.error(f"[WorkingMemory] Failed to summarize conversation | tenant={tenant_id} error={str(e)}")
        return None


async def get_working_context(tenant_id: int, conversation_id: int, token_budget: int = 800) -> dict:
    """
    Constructs the working memory context block for the LLM.
    Includes the latest summary and as many recent raw turns as fit in the budget.
    (Rough approximation: 1 token ≈ 4 characters).
    
    Parameters:
        tenant_id: Mandatory tenant scope.
        conversation_id: Maps to ai_chat_id.
        token_budget: Max allowed tokens for this block.
        
    Returns:
        dict containing 'summary' and 'recent_turns'.
    """
    pool = await get_pool()
    
    async with pool.acquire() as conn:
        latest_summary = await conn.fetchrow(
            """
            SELECT summary_text 
            FROM chat_summaries
            WHERE tenant_id = $1 AND conversation_id = $2
            ORDER BY id DESC LIMIT 1
            """,
            tenant_id, conversation_id
        )
        
    summary_text = latest_summary["summary_text"] if latest_summary else ""
    summary_tokens = len(summary_text) // 4
    
    remaining_budget = max(0, token_budget - summary_tokens)
    
    # Fetch recent turns (get up to 10 and truncate by length)
    recent_turns = await get_raw_chat_turns(tenant_id, conversation_id, limit=10)
    
    included_turns = []
    current_tokens = 0
    
    # Iterate backwards (newest to oldest) to ensure we always include the most recent
    for turn in reversed(recent_turns):
        turn_text = f"{turn['role']}: {turn['content']}"
        turn_tokens = len(turn_text) // 4
        
        if current_tokens + turn_tokens <= remaining_budget or not included_turns:
            # We always include at least one turn even if it blows the budget slightly
            included_turns.insert(0, turn)
            current_tokens += turn_tokens
        else:
            break
            
    return {
        "summary": summary_text,
        "recent_turns": included_turns
    }
