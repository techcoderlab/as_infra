import pytest
from unittest.mock import AsyncMock, MagicMock, patch
import json
import logging

pytestmark = pytest.mark.asyncio

# ─────────────────────────────────────────────────────
# 1. Fact Upsertion Tests (Versioning Logic)
# ─────────────────────────────────────────────────────

class TestEpisodicUpsert:
    
    @patch("memory.episodic.get_pool")
    async def test_upsert_new_fact(self, mock_get_pool):
        """
        If no active fact exists, upsert_fact should insert a new one and return 'inserted'.
        """
        from memory.episodic import upsert_fact
        
        # Mock pool and connection
        mock_conn = MagicMock()
        mock_conn.fetchrow = AsyncMock(return_value=None)  # No active fact found
        mock_conn.execute = AsyncMock()
        
        # Setup transaction context manager
        tx_cm = AsyncMock()
        tx_cm.__aenter__.return_value = None
        mock_conn.transaction.return_value = tx_cm
        
        # Setup acquire context manager
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool = MagicMock()
        mock_pool.acquire.return_value = acquire_cm
        mock_get_pool.return_value = mock_pool
        
        result = await upsert_fact(
            tenant_id=1,
            lead_id=10,
            fact_type="budget",
            fact_value="$1000",
            confidence=0.9
        )
        
        assert result == "inserted"
        
        # Ensure only INSERT was called, no UPDATE
        executed_sqls = [call[0][0] for call in mock_conn.execute.call_args_list]
        assert any("INSERT INTO episodic_facts" in sql for sql in executed_sqls)
        assert not any("UPDATE episodic_facts" in sql for sql in executed_sqls)
        

    @patch("memory.episodic.get_pool")
    async def test_upsert_identical_fact_ignored(self, mock_get_pool):
        """
        If an identical active fact exists, upsert_fact should do nothing and return 'ignored'.
        """
        from memory.episodic import upsert_fact
        
        # Mock pool returning an existing identical fact
        mock_conn = MagicMock()
        mock_conn.fetchrow = AsyncMock(return_value={
            "id": 100,
            "fact_value": "$1000",
            "confidence": 0.9
        })
        mock_conn.execute = AsyncMock()
        
        tx_cm = AsyncMock()
        tx_cm.__aenter__.return_value = None
        mock_conn.transaction.return_value = tx_cm
        
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool = MagicMock()
        mock_pool.acquire.return_value = acquire_cm
        mock_get_pool.return_value = mock_pool
        
        result = await upsert_fact(
            tenant_id=1,
            lead_id=10,
            fact_type="budget",
            fact_value="$1000",
            confidence=0.9
        )
        
        assert result == "ignored"
        mock_conn.execute.assert_not_called()


    @patch("memory.episodic.get_pool")
    async def test_upsert_changed_fact_superseded(self, mock_get_pool):
        """
        If a changed active fact exists, upsert_fact should update the old row, insert a new one, and return 'superseded'.
        """
        from memory.episodic import upsert_fact
        
        mock_conn = MagicMock()
        # Existing fact has value "$500"
        mock_conn.fetchrow = AsyncMock(return_value={
            "id": 100,
            "fact_value": "$500",
            "confidence": 0.9
        })
        mock_conn.execute = AsyncMock()
        
        tx_cm = AsyncMock()
        tx_cm.__aenter__.return_value = None
        mock_conn.transaction.return_value = tx_cm
        
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool = MagicMock()
        mock_pool.acquire.return_value = acquire_cm
        mock_get_pool.return_value = mock_pool
        
        result = await upsert_fact(
            tenant_id=1,
            lead_id=10,
            fact_type="budget",
            fact_value="$1000",  # New value
            confidence=0.9
        )
        
        assert result == "superseded"
        
        # Ensure BOTH UPDATE and INSERT were called
        executed_sqls = [call[0][0] for call in mock_conn.execute.call_args_list]
        assert any("UPDATE episodic_facts" in sql for sql in executed_sqls)
        assert any("INSERT INTO episodic_facts" in sql for sql in executed_sqls)


# ─────────────────────────────────────────────────────
# 2. Fact Extraction Tests
# ─────────────────────────────────────────────────────

class TestEpisodicExtraction:
    
    @patch("memory.episodic.get_gemini_client")
    async def test_extract_facts_valid_json(self, mock_get_client):
        """
        Tests if extract_facts correctly parses a valid JSON array from the LLM.
        """
        from memory.episodic import extract_facts
        
        mock_response = AsyncMock()
        mock_response.text = json.dumps([
            {"fact_type": "name", "fact_value": "John", "confidence": 0.99},
            {"fact_type": "likes_pizza", "fact_value": "True"}
        ])
        
        mock_client_instance = AsyncMock()
        mock_client_instance.aio.models.generate_content.return_value = mock_response
        mock_get_client.return_value = mock_client_instance
        
        recent_turns = [
            {"role": "user", "content": "I am John and I love pizza."}
        ]
        
        facts = await extract_facts(tenant_id=1, lead_id=10, recent_turns=recent_turns)
        
        assert len(facts) == 2
        assert facts[0]["fact_type"] == "name"
        assert facts[0]["fact_value"] == "John"
        assert facts[0]["confidence"] == 0.99
        
        assert facts[1]["fact_type"] == "likes_pizza"
        assert facts[1]["fact_value"] == "True"
        assert facts[1]["confidence"] == 1.0  # Defaulted
        

    @patch("memory.episodic.get_gemini_client")
    async def test_extract_facts_invalid_json(self, mock_get_client):
        """
        Tests if extract_facts gracefully handles invalid JSON from the LLM.
        """
        from memory.episodic import extract_facts
        
        mock_response = AsyncMock()
        mock_response.text = "This is not JSON."
        
        mock_client_instance = AsyncMock()
        mock_client_instance.aio.models.generate_content.return_value = mock_response
        mock_get_client.return_value = mock_client_instance
        
        recent_turns = [{"role": "user", "content": "Hello"}]
        
        facts = await extract_facts(tenant_id=1, lead_id=10, recent_turns=recent_turns)
        
        assert facts == []
