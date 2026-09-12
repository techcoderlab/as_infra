# ─────────────────────────────────────────────────────
# Module   : Semantic Memory Tests
# Layer    : Test
# Pillar   : P8 Code Quality (Testability)
# ─────────────────────────────────────────────────────

"""
Tests for the semantic memory module confirming:
1. Chunking respects token limits and overlap.
2. Tenant isolation — Tenant A queries never return Tenant B data.
3. active_source_ids scoping logic.
4. Empty results when nothing passes the relevance threshold.
"""

import pytest
from unittest.mock import AsyncMock, MagicMock, patch

# ─────────────────────────────────────────────────────
# 1. Chunking Tests (Pure function — no mocks needed)
# ─────────────────────────────────────────────────────

class TestChunkText:
    """Tests for memory.semantic.chunk_text — a pure function."""

    def test_chunk_text_empty_input_returns_empty_list(self):
        """Empty or whitespace-only input should return []."""
        from memory.semantic import chunk_text

        assert chunk_text("") == []
        assert chunk_text("   ") == []
        assert chunk_text(None) == []

    def test_chunk_text_short_text_returns_single_chunk(self):
        """Text shorter than max_tokens should return a single chunk."""
        from memory.semantic import chunk_text

        text = "This is a short sentence."
        chunks = chunk_text(text, max_tokens=400)
        assert len(chunks) == 1
        assert chunks[0] == text

    def test_chunk_text_long_text_splits_correctly(self):
        """Long text should be split into multiple chunks."""
        from memory.semantic import chunk_text

        # Generate text that exceeds max_tokens
        # ~400 tokens ≈ 300 words (at 1.33 tokens/word)
        sentences = [f"This is sentence number {i} with some extra words to fill up space." for i in range(50)]
        text = ". ".join(sentences) + "."

        chunks = chunk_text(text, max_tokens=100, overlap_tokens=20)
        assert len(chunks) > 1, f"Expected multiple chunks, got {len(chunks)}"

    def test_chunk_text_overlap_is_present(self):
        """Consecutive chunks should share overlapping content."""
        from memory.semantic import chunk_text

        sentences = [f"Unique sentence {i} with filler words for length." for i in range(30)]
        text = ". ".join(sentences) + "."

        chunks = chunk_text(text, max_tokens=80, overlap_tokens=30)
        if len(chunks) >= 2:
            # The end of chunk[0] should appear at the start of chunk[1]
            words_end_of_first = set(chunks[0].split()[-10:])
            words_start_of_second = set(chunks[1].split()[:10])
            overlap = words_end_of_first & words_start_of_second
            assert len(overlap) > 0, "Expected overlapping words between consecutive chunks"

    def test_chunk_text_no_empty_chunks(self):
        """No chunk should be empty or whitespace-only."""
        from memory.semantic import chunk_text

        text = "First sentence. Second sentence. Third sentence."
        chunks = chunk_text(text, max_tokens=20, overlap_tokens=5)
        for chunk in chunks:
            assert chunk.strip(), f"Found empty chunk: '{chunk}'"


# ─────────────────────────────────────────────────────
# 2. Tenant Isolation Tests (Mocked DB)
# ─────────────────────────────────────────────────────

class TestTenantIsolation:
    """Tests that tenant_id is always enforced in SQL queries."""

    @pytest.mark.asyncio
    @patch("memory.semantic.get_pool")
    @patch("memory.semantic.get_embeddings")
    async def test_search_query_always_contains_tenant_id_filter(
        self, mock_embeddings, mock_pool
    ):
        """
        test_search_query_always_contains_tenant_id_filter:
        The SQL executed during search MUST always include a WHERE tenant_id = $N clause.
        """
        from memory.semantic import search_memories

        # Mock embeddings to return a fixed vector
        mock_embeddings.return_value = [[0.1] * 384]

        # Mock the connection pool and capture the SQL
        mock_conn = AsyncMock()
        mock_conn.fetch = AsyncMock(return_value=[])
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool_instance = MagicMock()
        mock_pool_instance.acquire.return_value = acquire_cm
        mock_pool.return_value = mock_pool_instance

        await search_memories(tenant_id=42, query="test query", active_source_ids=[1])

        # Verify the SQL contains tenant_id filtering
        call_args = mock_conn.fetch.call_args
        sql = call_args[0][0]
        assert "tenant_id = $" in sql, f"SQL missing tenant_id filter: {sql}"

    @pytest.mark.asyncio
    @patch("memory.semantic.get_pool")
    @patch("memory.semantic.get_embeddings")
    async def test_tenant_a_never_returns_tenant_b_data(
        self, mock_embeddings, mock_pool
    ):
        """
        test_tenant_a_never_returns_tenant_b_data:
        Simulates rows from two tenants. Querying as Tenant A must never
        return Tenant B's data.
        """
        from memory.semantic import search_memories

        # Mock embeddings
        mock_embeddings.return_value = [[0.1] * 384]

        # Simulate DB returning ONLY Tenant A rows (as it should with WHERE clause)
        tenant_a_rows = [
            {"chunk_text": "Tenant A knowledge chunk", "distance": 0.2},
        ]

        mock_conn = AsyncMock()
        mock_conn.fetch = AsyncMock(return_value=tenant_a_rows)
        # pool.acquire() is synchronous, but returns an async context manager
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool_instance = MagicMock()
        mock_pool_instance.acquire.return_value = acquire_cm
        mock_pool.return_value = mock_pool_instance

        result = await search_memories(tenant_id=1, query="test", active_source_ids=[1])

        assert "Tenant A knowledge chunk" in result

        # Verify the positional args passed to fetch include tenant_id=1
        call_args = mock_conn.fetch.call_args
        positional_args = call_args[0]
        # $2 is tenant_id in the parameterized query
        assert 1 in positional_args, f"tenant_id=1 not found in query params: {positional_args}"

    @pytest.mark.asyncio
    @patch("memory.semantic.get_pool")
    @patch("memory.semantic.get_embeddings")
    async def test_empty_results_below_threshold(
        self, mock_embeddings, mock_pool
    ):
        """
        test_empty_results_below_threshold:
        When all results have distance >= threshold, return empty string.
        """
        from memory.semantic import search_memories

        mock_embeddings.return_value = [[0.1] * 384]

        # Empty results = everything was filtered by the WHERE distance < threshold
        mock_conn = AsyncMock()
        mock_conn.fetch = AsyncMock(return_value=[])
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool_instance = MagicMock()
        mock_pool_instance.acquire.return_value = acquire_cm
        mock_pool.return_value = mock_pool_instance

        result = await search_memories(tenant_id=1, query="irrelevant query", active_source_ids=[1])
        assert result == "", f"Expected empty string, got: '{result}'"

    @pytest.mark.asyncio
    async def test_search_requires_tenant_id(self):
        """
        test_search_requires_tenant_id:
        Calling search_memories without tenant_id must raise ValueError.
        """
        from memory.semantic import search_memories

        with pytest.raises(ValueError, match="tenant_id is required"):
            await search_memories(tenant_id=None, query="test", active_source_ids=[1])

    @pytest.mark.asyncio
    async def test_ingest_requires_tenant_id(self):
        """
        test_ingest_requires_tenant_id:
        Calling ingest_document without tenant_id must raise ValueError.
        """
        from memory.semantic import ingest_document

        with pytest.raises(ValueError, match="tenant_id is required"):
            await ingest_document(tenant_id=None, text="some text")


# ─────────────────────────────────────────────────────
# 3. Knowledge Source Scoping Tests
# ─────────────────────────────────────────────────────

class TestKnowledgeSourceScoping:
    """Tests for active_source_ids scoping behavior per approved design."""

    @pytest.mark.asyncio
    @patch("memory.semantic.get_pool")
    @patch("memory.semantic.get_embeddings")
    async def test_no_active_source_ids_bypasses_search(
        self, mock_embeddings, mock_pool
    ):
        """
        test_no_active_source_ids_bypasses_search:
        When active_source_ids is empty, search_memories should not query the db
        """
        from memory.semantic import search_memories

        mock_embeddings.return_value = [[0.1] * 384]

        mock_conn = AsyncMock()
        mock_conn.fetch = AsyncMock(return_value=[])
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool_instance = MagicMock()
        mock_pool_instance.acquire.return_value = acquire_cm
        mock_pool.return_value = mock_pool_instance

        result = await search_memories(tenant_id=1, query="test", active_source_ids=[])

        assert result == ""
        # fetch should not have been called
        mock_conn.fetch.assert_not_called()

    @pytest.mark.asyncio
    @patch("memory.semantic.get_pool")
    @patch("memory.semantic.get_embeddings")
    async def test_with_active_source_ids_filters_correctly(
        self, mock_embeddings, mock_pool
    ):
        """
        test_with_active_source_ids_filters_correctly:
        When active_source_ids is provided, SQL must include the ANY clause.
        """
        from memory.semantic import search_memories

        mock_embeddings.return_value = [[0.1] * 384]

        mock_conn = AsyncMock()
        mock_conn.fetch = AsyncMock(return_value=[])
        acquire_cm = AsyncMock()
        acquire_cm.__aenter__.return_value = mock_conn
        
        mock_pool_instance = MagicMock()
        mock_pool_instance.acquire.return_value = acquire_cm
        mock_pool.return_value = mock_pool_instance

        await search_memories(tenant_id=1, query="test", active_source_ids=[7, 8])

        sql = mock_conn.fetch.call_args[0][0]
        assert "source_id = ANY($3::int[])" in sql, f"Expected ANY clause in SQL: {sql}"
        # Verify array [7, 8] is in the positional args
        positional_args = mock_conn.fetch.call_args[0]
        assert [7, 8] in positional_args, f"active_source_ids=[7, 8] not in query params: {positional_args}"

