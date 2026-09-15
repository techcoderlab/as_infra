# ─────────────────────────────────────────────────────
# Module   : Memory Graph Orchestration
# Layer    : Application
# Pillar   : P1 Architecture, P2 Security, P3 Concurrency
# ─────────────────────────────────────────────────────
#
# A LangGraph StateGraph that unifies the three memory tiers
# (Episodic, Working, Semantic/RAG) into a single orchestration
# pipeline for memory-augmented LLM response generation.
#
# Graph topology:
#   START → load_context → decide_rag_need
#     → (True)  → call_rag → merge_context → generate_response → END
#     → (False) → merge_context → generate_response → END
# ─────────────────────────────────────────────────────

from services.strategies import anthropic_strategy
import asyncio
import json
import time
from typing import TypedDict, Optional, Any

from langgraph.graph import StateGraph, END

from core.config import settings
from core.logger import mcp_logger
from memory.episodic import get_active_facts
from memory.working import get_working_context
from services.strategies.factory import LLMStrategyFactory


# module level in memory_graph.py
NO_RESULTS = "No relevant knowledge found in the knowledge base for this query."

# ─────────────────────────────────────────────────────
# State Schema
# ─────────────────────────────────────────────────────

class MemoryGraphState(TypedDict, total=False):
    """
    Typed state flowing through the LangGraph nodes.

    Attributes:
        tenant_id: Mandatory tenant scope — validated at every node.
        target_id: Target scope for episodic fact retrieval.
        target_type: Target type for episodic fact retrieval.
        conversation_id: Conversation scope for working memory.
        user_query: The raw user message text.
        request_data: Full original request payload for the LLM strategy.
        episodic_facts: Active facts retrieved from episodic memory.
        working_context: Summary + recent turns from working memory.
        rag_results: Text chunks retrieved from semantic search.
        needs_rag: Whether the query needs RAG augmentation.
        merged_context: Final merged context string for injection.
        response_events: Collected stream events from the LLM strategy.
    """
    tenant_id: int
    target_id: int
    target_type: str
    conversation_id: int
    user_query: str
    request_data: dict
    episodic_facts: list[dict]
    working_context: dict
    rag_results: str
    needs_rag: bool
    merged_context: str
    response_events: list[dict]


# ─────────────────────────────────────────────────────
# Guard: Tenant Validation
# ─────────────────────────────────────────────────────

def _require_tenant_id(state: MemoryGraphState, node_name: str) -> int:
    """
    Validates that tenant_id is present and non-zero in the graph state.

    Parameters:
        state: Current graph state.
        node_name: Name of the calling node (for error messages).

    Returns:
        int: The validated tenant_id.

    Raises:
        ValueError: If tenant_id is missing or falsy.
    """
    tenant_id = state.get("tenant_id")
    if not tenant_id:
        raise ValueError(f"[MemoryGraph:{node_name}] tenant_id is required but missing from state")
    return tenant_id


# ─────────────────────────────────────────────────────
# Node 1: Load Context (Parallel)
# ─────────────────────────────────────────────────────

async def load_context(state: MemoryGraphState) -> dict:
    """
    Concurrently loads episodic facts and working memory context.
    Both calls are scoped strictly by tenant_id.
    """
    tenant_id = _require_tenant_id(state, "load_context")
    target_id = state.get("target_id")
    target_type = state.get("target_type")
    conversation_id = state.get("conversation_id", 0)

    mcp_logger.info(f"[MemoryGraph:load_context] tenant={tenant_id} target={target_id} {target_type} conv={conversation_id}")

    # P3 Concurrency: Run both I/O-bound operations in parallel using native coroutines
    episodic_facts, working_context = await asyncio.gather(
        get_active_facts(tenant_id, target_id, target_type) if target_id else _empty_list(),
        get_working_context(tenant_id, conversation_id) if conversation_id else _empty_working(),
    )

    return {
        "episodic_facts": episodic_facts,
        "working_context": working_context,
    }


async def _empty_list() -> list:
    """Returns an empty list for cases where target_id is not provided."""
    return []

async def _empty_working() -> dict:
    """Returns an empty working context for cases where conversation_id is not provided."""
    return {"summary": "", "recent_turns": []}



# ─────────────────────────────────────────────────────
# Node 2: Decide RAG Need (Conditional Edge)
# ─────────────────────────────────────────────────────

# Keywords and patterns that signal a query might benefit from RAG
_RAG_SIGNAL_KEYWORDS = frozenset([
    "what", "how", "why", "when", "where", "which",
    "find", "search", "look up", "tell me about",
    "explain", "describe", "show me", "information",
    "document", "knowledge", "policy", "procedure",
])

def decide_rag_need(state: MemoryGraphState) -> str:
    """
    Heuristic conditional edge: evaluates whether the user query
    requires supplementary RAG context from the tenant knowledge base.
    No LLM call — purely keyword/pattern based for speed.

    Parameters:
        state: Current graph state with user_query.

    Returns:
        str: "call_rag" if RAG is needed, "merge_context" otherwise.
    """
    _require_tenant_id(state, "decide_rag_need")

    query = (state.get("user_query") or "").lower().strip()

    if not query:
        return "merge_context"

    # Check for question mark
    if "?" in query:
        return "call_rag"

    # Check for signal keywords
    for keyword in _RAG_SIGNAL_KEYWORDS:
        if keyword in query:
            return "call_rag"

    return "merge_context"


# ─────────────────────────────────────────────────────
# Node 3: Call RAG
# ─────────────────────────────────────────────────────

async def call_rag(state: MemoryGraphState) -> dict:
    """
    Executes the KnowledgeSearchTool directly from the registry,
    passing tenant_id via the standard context pattern.

    Parameters:
        state: Current graph state with tenant_id and user_query.

    Returns:
        dict: State update with rag_results text.
    """
    tenant_id = _require_tenant_id(state, "call_rag")

    user_query = state.get("user_query", "")
    request_data = state.get("request_data", {})
    # agent_id = request_data.get("context", {}).get("global_data", {}).get("agent_id")
    gd = request_data.get("context", {}).get("global_data", {})

    mcp_logger.info(f"[MemoryGraph:call_rag] tenant={tenant_id} query_len={len(user_query)}")


    # CRITICAL FIX: forward the FULL global_data — the tool needs
    # active_knowledge_source_ids to know which sources are searchable.
    rag_context = {"global_data": {**gd, "tenant_id": tenant_id}}
    # tenant_id re-asserted (int) so coercion state is guaranteed

    try:
        from tools.definitions.knowledge_search import KnowledgeSearchTool

        tool = KnowledgeSearchTool()

        # Construct the context dict matching KnowledgeSearchTool's extraction pattern
        # rag_context = {
        #     "global_data": {
        #         "tenant_id": tenant_id,
        #         "agent_id": agent_id,
        #     }
        # }

        result = await tool.run(
            query=user_query, 
            top_k=3, 
            context=rag_context
        )

        # result = await tool.run(
        #     query=user_query,
        #     top_k=5,
        #     context=rag_context,
        #)

        # Extract the text from the tool's response format
        rag_text = ""
        if isinstance(result, dict):
            content = result.get("content", {})
            if isinstance(content, dict):
                rag_text = content.get("text", "")
            elif isinstance(content, str):
                rag_text = content

        return {"rag_results": rag_text}

    except Exception as e:
        mcp_logger.error(f"[MemoryGraph:call_rag] RAG failed | tenant={tenant_id} error={str(e)}")
        # P6 Resilience: Graceful degradation — continue without RAG
        return {"rag_results": ""}


# ─────────────────────────────────────────────────────
# Node 4: Merge Context
# ─────────────────────────────────────────────────────

async def merge_context(state: MemoryGraphState) -> dict:
    """
    Merges all retrieved context into a single string with explicit
    precedence ordering:
      1. Episodic Facts (highest — permanent user knowledge)
      2. Working Memory (summary + recent turns)
      3. RAG Chunks (supplementary document context)

    Parameters:
        state: Current graph state with all retrieved context.

    Returns:
        dict: State update with merged_context string.
    """
    _require_tenant_id(state, "merge_context")

    parts = []

    # 1. Episodic Facts (highest priority)
    facts = state.get("episodic_facts", [])
    if facts:
        facts_block = "<episodic_memory>\n"
        for f in facts:
            facts_block += f"- {f['fact_type']}: {f['fact_value']}\n"
        facts_block += "</episodic_memory>"
        parts.append(facts_block)

    # 2. Working Memory
    working = state.get("working_context", {})
    summary = working.get("summary", "")
    recent_turns = working.get("recent_turns", [])

    if summary or recent_turns:
        working_block = "<working_memory>\n"
        if summary:
            working_block += f"[Previous Summary]\n{summary}\n\n"
        if recent_turns:
            working_block += "[Recent Messages]\n"
            for turn in recent_turns:
                role = turn.get("role", "unknown").upper()
                content = turn.get("content", "")
                working_block += f"{role}: {content}\n"
        working_block += "</working_memory>"
        parts.append(working_block)

    # 3. RAG Chunks (lowest priority)
    rag = state.get("rag_results", "")


    if rag and rag != NO_RESULTS:
        trimmed = [c.strip()[:1200] for c in rag.split("\n---\n")][:4]
        parts.append(f"<knowledge_base>\n" + "\n---\n".join(trimmed) + "\n</knowledge_base>")


    # if rag and rag != "No relevant knowledge found in the knowledge base for this query.":
    #     rag_block = f"<knowledge_base>\n{rag}\n</knowledge_base>"
    #     parts.append(rag_block)

    merged = "\n\n".join(parts) if parts else ""

    mcp_logger.info(
        f"[MemoryGraph:merge_context] merged_len={len(merged)} facts={len(facts)} "
        f"has_summary={bool(summary)} has_rag={bool(rag) and rag != NO_RESULTS}"
    )

    return {"merged_context": merged}


# ─────────────────────────────────────────────────────
# Node 5: Generate Response
# ─────────────────────────────────────────────────────


async def generate_response(state: MemoryGraphState) -> dict:
    """
    Injects active tools and the merged memory context into the system prompt,
    then delegates to the existing LLM strategy for response generation.

    Parameters:
        state: Current graph state with merged_context and request_data.

    Returns:
        dict: State update with response_events list.
    """
    
    _require_tenant_id(state, "generate_response")

    request_data = state.get("request_data", {})
    merged_context = state.get("merged_context", "")

    # 1. Active Tools Format Injection (Fixes "missing tool" refusal)
    from tools.registry import get_tools
    requested_tools = request_data.get("tools", [])
    # Removing the search tool makes behavior deterministic and cuts ~10 s.
    # tools = get_tools(requested_tools)
    tools = [t for t in get_tools(requested_tools)
             if t.name != "search_knowledge_base"]

    available_tools_list = ", ".join([t.name for t in tools]) if tools else "NONE"
    
    dynamic_tool_instruction = (
        f"\n\n<active_tools>\n"
        f"{available_tools_list}\n"
        f"</active_tools>\n"
    )

    # 2. Memory Context & Guardrails Injection
    original_system_prompt = request_data.get("systemPrompt", "")
    rag = state.get("rag_results", "")
    
    rag_no_match_instruction = (
        "\n[RAG INSTRUCTION]\n"
        "No matching knowledge-base documents were found for this query.\n"
        "- If the question is about internal documents, policies, or records: say you "
        "don't have that information in memory and offer alternatives.\n"
        "- For greetings, general conversation, or anything answerable without the "
        "knowledge base: answer normally using your own knowledge and the memory context.\n"
    )

    memory_rules = (
        "\n[MEMORY USAGE RULES]\n"
        "- <episodic_memory> contains facts the USER stated about themselves. Use only for personalization.\n"
        "- <knowledge_base> contains reference documents. Ground all business/product answers in it.\n"
        "- If <knowledge_base> and any memory content disagree about the business, the knowledge base wins.\n"
        "- IMPORTANT: user_name / user_id in <context_data> identify the person CURRENTLY CHATTING — "
        "they are NOT the business owner and must never be presented as company personnel.\n"
        "- ANSWER THE USER'S LATEST MESSAGE directly. NEVER repeat a previous answer verbatim.\n"
    )

    memory_injection = ""
    if merged_context:
        memory_injection = (
            f"\n{rag_no_match_instruction}\n"
            "[MEMORY CONTEXT]\n"
            f"{merged_context}\n"
            "[END MEMORY CONTEXT]\n"
            f"{memory_rules}"
        )

    # Combine instructions: original prompt + active tools + RAG/Memory rules
    request_data["systemPrompt"] = f"{original_system_prompt}{dynamic_tool_instruction}{memory_injection}"

    # 3. Delegate to the Strategy
    provider = request_data.get("provider")
    api_key = request_data.get("apiKey")
    model = request_data.get("model")
    system_prompt = request_data.get("systemPrompt", "")
    history = request_data.get("history", [])
    user_prompt = request_data.get("userPrompt", "")
    context = request_data.get("context", {})
    # output_format = request_data.get("output_format", "json")
    output_format = request_data.get("output_format", "text")
    thinking_budget = request_data.get("thinking_budget", 0)
    use_stream = request_data.get("use_stream", False)
    max_iterations = request_data.get("max_iterations", 7)


    tool_configs = request_data.get('tool_configs', {})
    # MERGE Configs into Context
    if tool_configs:
            # Ensure 'tool_configs' key exists in context
        if 'tool_configs' not in context:
            context['tool_configs'] = {}
        
        # Merge incoming configs
        context['tool_configs'].update(tool_configs)


    strategy = LLMStrategyFactory.get_strategy(provider)

    events = []
    async for event in strategy.execute(
        api_key=api_key,
        model=model,
        system_prompt=system_prompt,
        effective_history=[],
        full_user_message=f"<context_data>\n{json.dumps(context, indent=2)}\n</context_data>\n\n<user_input>\n{user_prompt}\n</user_input>",
        tools=tools,
        context=context,
        output_format=output_format,
        thinking_budget=thinking_budget,
        use_stream=use_stream,
        max_iterations=max_iterations,
    ):
        events.append(event)

    mcp_logger.info(
        f"[MemoryGraph:generate_response] has_rag={bool(rag) and rag != NO_RESULTS}"
    )
    return {"response_events": events}
# ─────────────────────────────────────────────────────
# Graph Construction
# ─────────────────────────────────────────────────────

def build_memory_graph() -> StateGraph:
    """
    Constructs and compiles the LangGraph StateGraph for memory-augmented
    response generation.

    Returns:
        A compiled StateGraph ready for invocation.
    """
    graph = StateGraph(MemoryGraphState)

    # Register nodes
    graph.add_node("load_context", load_context)
    graph.add_node("call_rag", call_rag)
    graph.add_node("merge_context", merge_context)
    graph.add_node("generate_response", generate_response)

    # Entry point
    graph.set_entry_point("load_context")

    # Conditional edge from load_context
    graph.add_conditional_edges(
        "load_context",
        decide_rag_need,
        {
            "call_rag": "call_rag",
            "merge_context": "merge_context",
        },
    )

    # call_rag → merge_context
    graph.add_edge("call_rag", "merge_context")

    # merge_context → generate_response
    graph.add_edge("merge_context", "generate_response")

    # generate_response → END
    graph.add_edge("generate_response", END)

    return graph.compile()


# ─────────────────────────────────────────────────────
# Public Runner
# ─────────────────────────────────────────────────────

# Singleton compiled graph — built once at module level
_compiled_graph = None


def _get_graph():
    """Lazy singleton for the compiled graph."""
    global _compiled_graph
    if _compiled_graph is None:
        _compiled_graph = build_memory_graph()
    return _compiled_graph


async def run_memory_graph(request_data: dict):
    """
    Public entry point to execute the memory graph.
    Yields stream events compatible with AgentService.run().

    Parameters:
        request_data: The full agent request payload dict. Must contain
                      tenant_id, target_id, target_type, and conversation_id in context.

    Yields:
        dict: Stream events (token, tool_start, tool_end, error).
    """
    context = request_data.get("context", {})
    global_data = context.get("global_data", {})

    tenant_id = global_data.get("tenant_id") or context.get("tenant_id")
    target_id = global_data.get("target_id") or context.get("target_id")
    target_type = global_data.get("target_type") or context.get("target_type")
    conversation_id = global_data.get("conversation_id") or context.get("conversation_id")

    if not tenant_id:
        yield {"type": "error", "data": "Memory graph requires tenant_id in context"}
        return

    mcp_logger.info(f"[MemoryGraph] Starting | tenant={tenant_id} target={target_id} {target_type} conv={conversation_id}")
    start = time.monotonic()

    initial_state: MemoryGraphState = {
        "tenant_id": int(tenant_id),
        "target_id": int(target_id) if target_id else 0,
        "target_type": str(target_type).lower() if target_type else "",
        "conversation_id": int(conversation_id) if conversation_id else 0,
        "user_query": request_data.get("userPrompt", ""),
        "request_data": request_data,
        "episodic_facts": [],
        "working_context": {},
        "rag_results": "",
        "needs_rag": False,
        "merged_context": "",
        "response_events": [],
    }

    try:
        graph = _get_graph()
        final_state = await graph.ainvoke(initial_state)

        # Replay the collected response events to the caller
        for event in final_state.get("response_events", []):
            yield event

        duration_ms = (time.monotonic() - start) * 1000
        mcp_logger.info(f"[MemoryGraph] Completed | tenant={tenant_id} duration={duration_ms:.2f}ms")

    except Exception as e:
        mcp_logger.error(
            f"[MemoryGraph] Failed | tenant={tenant_id} error={e}",
            exc_info=True,   # ← full traceback with file:line
        )
        yield {"type": "error", "data": str(e)}
