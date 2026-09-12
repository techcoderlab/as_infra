import pytest
from unittest.mock import AsyncMock, MagicMock, patch
import json

pytestmark = pytest.mark.asyncio

# ─────────────────────────────────────────────────────
# Fake Seed Data
# ─────────────────────────────────────────────────────

TENANT_101_FACTS = [
    {"fact_type": "secret_project", "fact_value": "Project Apollo (101)"}
]
TENANT_202_FACTS = [
    {"fact_type": "secret_project", "fact_value": "Project Zeus (202)"}
]

TENANT_101_SUMMARY = "User 101 loves space exploration."
TENANT_202_SUMMARY = "User 202 loves deep sea diving."

TENANT_101_TURNS = [
    {"id": 1, "role": "user", "content": "How is Apollo going?"}
]
TENANT_202_TURNS = [
    {"id": 1, "role": "user", "content": "How is Zeus going?"}
]

TENANT_101_RAG = "Apollo launch scheduled for Tuesday."
TENANT_202_RAG = "Zeus dive scheduled for Friday."


async def mock_get_active_facts_side_effect(tenant_id, lead_id):
    if tenant_id == 101:
        return TENANT_101_FACTS
    elif tenant_id == 202:
        return TENANT_202_FACTS
    return []


async def mock_get_working_context_side_effect(tenant_id, conversation_id, token_budget=800):
    if tenant_id == 101:
        return {"summary": TENANT_101_SUMMARY, "recent_turns": TENANT_101_TURNS}
    elif tenant_id == 202:
        return {"summary": TENANT_202_SUMMARY, "recent_turns": TENANT_202_TURNS}
    return {"summary": "", "recent_turns": []}


async def mock_search_memories_side_effect(tenant_id, query, agent_id=None, top_k=5):
    if tenant_id == 101:
        return TENANT_101_RAG
    elif tenant_id == 202:
        return TENANT_202_RAG
    return ""


# ─────────────────────────────────────────────────────
# Tests
# ─────────────────────────────────────────────────────

class TestGraphTenantIsolation:

    @patch("services.orchestration.memory_graph.get_active_facts", side_effect=mock_get_active_facts_side_effect)
    @patch("services.orchestration.memory_graph.get_working_context", side_effect=mock_get_working_context_side_effect)
    @patch("memory.semantic.search_memories", side_effect=mock_search_memories_side_effect)
    @patch("services.orchestration.memory_graph.LLMStrategyFactory.get_strategy")
    async def test_tenant_101_never_sees_202_data(
        self, mock_get_strategy, mock_search, mock_working, mock_facts
    ):
        """
        Verify that executing the memory graph for Tenant 101 NEVER bleeds
        Tenant 202 data into the LLM system prompt or context.
        """
        from services.orchestration.memory_graph import run_memory_graph

        # Mock the LLM generator to just return a dummy token
        async def dummy_execute(*args, **kwargs):
            yield {"type": "token", "data": "Response"}

        mock_execute = MagicMock()
        mock_execute.side_effect = dummy_execute

        mock_strategy = MagicMock()
        mock_strategy.execute = mock_execute
        mock_get_strategy.return_value = mock_strategy

        request_data = {
            "provider": "openai",
            "apiKey": "fake",
            "model": "gpt-4",
            "userPrompt": "What is the secret project? (trigger RAG)", # Query triggers decide_rag_need
            "context": {
                "tenant_id": 101,
                "lead_id": 1,
                "conversation_id": 1
            }
        }

        # Run the graph
        events = []
        async for event in run_memory_graph(request_data):
            events.append(event)

        # 1. Assert Strategy was called exactly once
        assert mock_strategy.execute.call_count == 1
        
        # 2. Inspect the injected system prompt sent to the LLM
        call_kwargs = mock_strategy.execute.call_args[1]
        system_prompt = call_kwargs.get("system_prompt", "")
        
        # 3. Assert Tenant 101 data IS present
        assert "Project Apollo (101)" in system_prompt
        assert "User 101 loves space exploration." in system_prompt
        assert "How is Apollo going?" in system_prompt
        assert "Apollo launch scheduled for Tuesday." in system_prompt

        # 4. Assert Tenant 202 data is NEVER present (Strict Isolation)
        assert "202" not in system_prompt
        assert "Project Zeus" not in system_prompt
        assert "deep sea diving" not in system_prompt
        assert "Zeus dive" not in system_prompt


    @patch("services.orchestration.memory_graph.get_active_facts", side_effect=mock_get_active_facts_side_effect)
    @patch("services.orchestration.memory_graph.get_working_context", side_effect=mock_get_working_context_side_effect)
    @patch("memory.semantic.search_memories", side_effect=mock_search_memories_side_effect)
    @patch("services.orchestration.memory_graph.LLMStrategyFactory.get_strategy")
    async def test_tenant_202_never_sees_101_data(
        self, mock_get_strategy, mock_search, mock_working, mock_facts
    ):
        """
        Verify the reverse: Tenant 202 execution never bleeds Tenant 101 data.
        """
        from services.orchestration.memory_graph import run_memory_graph

        async def dummy_execute(*args, **kwargs):
            yield {"type": "token", "data": "Response"}

        mock_execute = MagicMock()
        mock_execute.side_effect = dummy_execute

        mock_strategy = MagicMock()
        mock_strategy.execute = mock_execute
        mock_get_strategy.return_value = mock_strategy

        request_data = {
            "provider": "openai",
            "apiKey": "fake",
            "model": "gpt-4",
            "userPrompt": "What is the secret project? (trigger RAG)",
            "context": {
                "tenant_id": 202,
                "lead_id": 2,
                "conversation_id": 2
            }
        }

        events = []
        async for event in run_memory_graph(request_data):
            events.append(event)

        assert mock_strategy.execute.call_count == 1
        
        call_kwargs = mock_strategy.execute.call_args[1]
        system_prompt = call_kwargs.get("system_prompt", "")
        
        # Assert Tenant 202 data IS present
        assert "Project Zeus (202)" in system_prompt
        assert "User 202 loves deep sea diving." in system_prompt
        assert "How is Zeus going?" in system_prompt
        assert "Zeus dive scheduled for Friday." in system_prompt

        # Assert Tenant 101 data is NEVER present (Strict Isolation)
        assert "101" not in system_prompt
        assert "Project Apollo" not in system_prompt
        assert "space exploration" not in system_prompt
        assert "Apollo launch" not in system_prompt
