import pytest
from unittest.mock import AsyncMock, MagicMock, patch
import json
import logging

pytestmark = pytest.mark.asyncio

# ─────────────────────────────────────────────────────
# 1. Raw Chat Turns Tests (Isolation)
# ─────────────────────────────────────────────────────

class TestWorkingMemoryDB:

    @patch("memory.working.get_pool")
    async def test_get_raw_chat_turns_isolation(self, mock_get_pool):
        """
        Verify get_raw_chat_turns retrieves messages in chronological order and correctly joins 
        with ai_chats for tenant_id filtering.
        """
        from memory.working import get_raw_chat_turns
        
        mock_conn = MagicMock()
        # Mocking fetch to return newest-first as the SQL specifies (DESC)
        mock_conn.fetch = AsyncMock(return_value=[
            {"id": 3, "role": "ai", "content": "Sure!", "created_at": "2026-01-03"},
            {"id": 2, "role": "user", "content": "Help me", "created_at": "2026-01-02"},
            {"id": 1, "role": "ai", "content": "Hello", "created_at": "2026-01-01"},
        ])
        
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool = MagicMock()
        mock_pool.acquire.return_value = acquire_cm
        mock_get_pool.return_value = mock_pool
        
        turns = await get_raw_chat_turns(tenant_id=1, conversation_id=10, limit=3)
        
        # Should be reversed to chronological (ASC)
        assert len(turns) == 3
        assert turns[0]["id"] == 1
        assert turns[2]["id"] == 3
        
        # Verify SQL has the correct join for tenant isolation
        call_args = mock_conn.fetch.call_args
        sql = call_args[0][0]
        assert "JOIN ai_chats ac ON cm.ai_chat_id = ac.id" in sql
        assert "ac.tenant_id = $1" in sql
        
        
    @patch("memory.working.get_raw_chat_turns")
    @patch("memory.working.get_pool")
    async def test_get_working_context_budgeting(self, mock_get_pool, mock_get_raw_turns):
        """
        Verify get_working_context correctly budgets tokens between summary and raw turns.
        """
        from memory.working import get_working_context
        
        mock_conn = MagicMock()
        # Mock summary to be ~40 chars (10 tokens)
        mock_conn.fetchrow = AsyncMock(return_value={
            "summary_text": "This is a 40 character summary string..."
        })
        
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool = MagicMock()
        mock_pool.acquire.return_value = acquire_cm
        mock_get_pool.return_value = mock_pool
        
        # Mock raw turns: each ~40 chars (10 tokens)
        mock_get_raw_turns.return_value = [
            {"id": 1, "role": "user", "content": "This is a forty character raw turn str.A"},
            {"id": 2, "role": "ai", "content": "This is a forty character raw turn str.B"},
            {"id": 3, "role": "user", "content": "This is a forty character raw turn str.C"},
        ]
        
        # Budget = 25 tokens. Summary uses 10. Remaining = 15.
        # It should fit the newest turn (turn 3, 10 tokens). Turn 2 (10 tokens) would exceed the remaining budget.
        context = await get_working_context(tenant_id=1, conversation_id=10, token_budget=25)
        
        assert context["summary"] == "This is a 40 character summary string..."
        assert len(context["recent_turns"]) == 1
        assert context["recent_turns"][0]["id"] == 3
        
        
# ─────────────────────────────────────────────────────
# 2. Summarization Tests
# ─────────────────────────────────────────────────────

class TestWorkingMemorySummarization:
    
    @patch("memory.working.get_active_facts")
    @patch("memory.working.get_pool")
    @patch("memory.working.get_gemini_client")
    async def test_summarize_conversation_includes_facts(self, mock_get_client, mock_get_pool, mock_get_active_facts):
        """
        Verify summarize_conversation passes episodic facts to the LLM to avoid restating them.
        """
        from memory.working import summarize_conversation
        
        # Mock active facts
        mock_get_active_facts.return_value = [
            {"fact_type": "budget", "fact_value": "$5000 limit"}
        ]
        
        # Mock DB
        mock_conn = MagicMock()
        mock_conn.fetchrow = AsyncMock(return_value={"summary_text": "Old summary", "covers_up_to_turn": 5})
        mock_conn.fetch = AsyncMock(return_value=[
            {"id": 6, "role": "user", "content": "My budget is actually $5000 limit."}
        ])
        mock_conn.execute = AsyncMock()
        
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool = MagicMock()
        mock_pool.acquire.return_value = acquire_cm
        mock_get_pool.return_value = mock_pool
        
        # Mock LLM
        mock_response = AsyncMock()
        mock_response.text = "User reiterated their budget."
        
        mock_client_instance = AsyncMock()
        mock_client_instance.aio.models.generate_content.return_value = mock_response
        mock_get_client.return_value = mock_client_instance
        
        summary = await summarize_conversation(tenant_id=1, conversation_id=10, lead_id=42)
        
        assert summary == "User reiterated their budget."
        
        # Verify the prompt contained the facts
        call_args = mock_client_instance.aio.models.generate_content.call_args
        prompt = call_args[1]["contents"]
        
        assert "KNOWN EPISODIC FACTS" in prompt
        assert "$5000 limit" in prompt
        assert "Do NOT restate facts" in prompt
        
        # Verify the new summary was inserted
        executed_sqls = [call[0][0] for call in mock_conn.execute.call_args_list]
        assert any("INSERT INTO chat_summaries" in sql for sql in executed_sqls)
