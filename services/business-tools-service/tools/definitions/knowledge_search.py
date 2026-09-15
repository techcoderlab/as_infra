# ─────────────────────────────────────────────────────
# Module   : KnowledgeSearchTool
# Layer    : Presentation (Tool Interface)
# Pillar   : P1 Architecture, P2 Security, P4 Reliability
#
# FIXED    : LLM tool-call arguments are NOT runtime-validated against
#            args_schema — asyncpg is strict, so every numeric argument is
#            coerced defensively (LLMs frequently send 5 as "5").
#            - _as_int moved to module level (instance method lacked `self`)
#            - top_k coerced + clamped (prevents pathological scan sizes)
#            - active_source_ids elements coerced (JSON round-trip → strings)
# ─────────────────────────────────────────────────────

from typing import Any, Optional
from pydantic import BaseModel, Field

from tools.base import BaseTool
from core.logger import mcp_logger
from core.decorators import tool_timeout


def _coerce_int(value: Any, field: str) -> int:
    """
    Runtime coercion for LLM/tool arguments. asyncpg rejects '5' for an
    INTEGER parameter, and Pydantic args_schema does not validate at call time.
    Raises ValueError with a safe, non-secret message.
    """
    try:
        return int(str(value).strip())
    except (TypeError, ValueError):
        raise ValueError(f"{field} must be an integer, got: {value!r}")


class KnowledgeSearchArgs(BaseModel):
    """Arguments schema for the knowledge base search tool (LLM-facing contract)."""

    query: str = Field(
        ...,
        description="The natural language question or search term to find relevant knowledge.",
    )
    top_k: int = Field(
        5,
        description="Maximum number of relevant text chunks to return (1-20).",
    )


class KnowledgeSearchTool(BaseTool):
    """
    Searches the tenant-scoped vector knowledge base for semantically
    relevant text chunks. Returns matching content or an empty response
    if nothing passes the relevance threshold.
    """

    name = "search_knowledge_base"
    description = (
        "Searches the tenant's knowledge base for information relevant to the query. "
        "Use this when the user asks a question that might be answered by uploaded "
        "documents, prior conversations, or manually added knowledge. "
        "Returns relevant text passages or nothing if no relevant knowledge exists."
    )
    args_schema = KnowledgeSearchArgs

    # P2/P4: clamp LLM-controlled top_k — hard ceiling regardless of model output
    MAX_TOP_K = 20

    @staticmethod
    def _tool_error(message: str) -> dict:
        return {"isError": True, "content": {"type": "text", "text": message}}

    @tool_timeout(seconds=30)
    async def run(
        self,
        query: str,
        top_k: int = 5,
        context: Optional[dict] = None,
        **kwargs,
    ):
        """
        Executes a semantic search against tenant_vector_memories.

        Parameters:
            query: Natural-language query string.
            top_k: Max results to return (LLM-supplied — coerce + clamp).
            context: Execution context containing tenant_id and optional agent_id.

        Returns:
            dict: Tool response with relevant text chunks or error.
        """
        context = context or {}

        # P2 Security: tenant_id from context only — never from LLM arguments
        tenant_id = context.get("global_data", {}).get("tenant_id") or context.get("tenant_id")

        if not tenant_id:
            mcp_logger.error("[KnowledgeSearchTool] Missing Tenant Context. Aborting.")
            return self._tool_error(
                "Error: Missing Tenant Context. Cannot search without knowing "
                "which account to access."
            )

        # ── Runtime coercion (asyncpg is strictly typed; LLM args are not trusted) ──
        try:
            tenant_id = _coerce_int(tenant_id, "tenant_id")
            top_k     = _coerce_int(top_k, "top_k")
        except ValueError as e:
            mcp_logger.error(f"[KnowledgeSearchTool] Argument coercion failed: {e}")
            return self._tool_error("Invalid search parameters.")

        top_k = max(1, min(top_k, self.MAX_TOP_K))

        active_source_ids = context.get("global_data", {}).get("active_knowledge_source_ids", [])

        # Short-circuit if there are no active knowledge sources bound
        if not active_source_ids:
            return {
                "content": {
                    "type": "text",
                    "text": "No relevant knowledge found in the knowledge base for this query.",
                }
            }

        # Coerce source IDs — JSON round-trips deliver them as ["3", "7"]
        try:
            active_source_ids = [
                _coerce_int(sid, "active_knowledge_source_ids") for sid in active_source_ids
            ]
        except ValueError as e:
            mcp_logger.error(f"[KnowledgeSearchTool] Source ID coercion failed: {e}")
            return self._tool_error("Invalid knowledge source configuration.")

        try:
            # Lazy import to avoid loading sentence-transformers at module import time
            from memory.semantic import search_memories

            results = await search_memories(
                tenant_id=tenant_id,
                query=str(query),
                active_source_ids=active_source_ids,
                top_k=top_k,
            )

            if not results:
                return {
                    "content": {
                        "type": "text",
                        "text": "No relevant knowledge found in the knowledge base for this query.",
                    }
                }

            return {"content": {"type": "text", "text": results}}

        except Exception as e:
            mcp_logger.error(f"[KnowledgeSearchTool Error] {type(e).__name__}: {e}")
            return self._tool_error(
                "Knowledge base search failed. The service may be temporarily unavailable."
            )