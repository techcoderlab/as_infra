# ─────────────────────────────────────────────────────
# Module   : KnowledgeSearchTool
# Layer    : Presentation (Tool Interface)
# Pillar   : P1 Architecture, P2 Security
# ─────────────────────────────────────────────────────

from pydantic import BaseModel, Field
from typing import Optional
from tools.base import BaseTool
from core.logger import mcp_logger
from core.decorators import tool_timeout


class KnowledgeSearchArgs(BaseModel):
    """Arguments schema for the knowledge base search tool."""

    query: str = Field(
        ...,
        description="The natural language question or search term to find relevant knowledge.",
    )
    top_k: int = Field(
        5,
        description="Maximum number of relevant text chunks to return.",
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

    @tool_timeout(seconds=30)
    async def run(
        self,
        query: str,
        top_k: int = 5,
        context: dict = None,
        **kwargs,
    ):
        """
        Executes a semantic search against tenant_vector_memories.

        Parameters:
            query: Natural-language query string.
            top_k: Max results to return.
            context: Execution context containing tenant_id and optional agent_id.

        Returns:
            dict: Tool response with relevant text chunks or error.
        """
        context = context or {}

        # P2 Security: Extract tenant_id from context (same pattern as crm_read.py)
        tenant_id = context.get("global_data", {}).get("tenant_id") or context.get("tenant_id")

        if not tenant_id:
            mcp_logger.error("[KnowledgeSearchTool] Missing Tenant Context. Aborting.")
            return {
                "isError": True,
                "content": {
                    "type": "text",
                    "text": "Error: Missing Tenant Context. Cannot search without knowing which account to access.",
                },
            }

        # Extract active knowledge source IDs for filtering
        active_source_ids = context.get("global_data", {}).get("active_knowledge_source_ids", [])
        
        # Short-circuit if there are no active knowledge sources bound
        if not active_source_ids:
            return {
                "content": {
                    "type": "text",
                    "text": "No relevant knowledge found in the knowledge base for this query.",
                }
            }

        try:
            # Lazy import to avoid loading sentence-transformers at module import time
            from memory.semantic import search_memories

            results = await search_memories(
                tenant_id=int(tenant_id),
                query=query,
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

            return {
                "content": {
                    "type": "text",
                    "text": results,
                }
            }

        except Exception as e:
            mcp_logger.error(f"[KnowledgeSearchTool Error] {str(e)}")
            return {
                "isError": True,
                "content": {
                    "type": "text",
                    "text": "Knowledge base search failed. The service may be temporarily unavailable.",
                },
            }
