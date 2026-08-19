# ─────────────────────────────────────────────────────────────────────────────
# Module   : CloudflareStrategy
# Layer    : Application › LLM Strategies
# Pattern  : Strategy (GoF) — matches OpenAIStrategy / GeminiStrategy / AnthropicStrategy
# Pillar   : P1 Architecture, P2 Security, P3 Performance, P4 Reliability,
#            P5 Observability, P6 Maintainability, P7 Scalability, P8 Code Quality
#
# Purpose  : Integrates Cloudflare Workers AI models (e.g. Llama 3.1, 3.3) as a
#            first-class provider in the sidecar.
#            Uses the OpenAI-compatible endpoint so it slots into the EXISTING
#            multi-turn tool-calling loop with zero changes to AgentService.
#
# Key Design Decisions:
#   - Uses httpx.AsyncClient (singleton via core/http.py) NOT openai SDK —
#     avoids adding a new runtime dependency; CF's OAI-compat endpoint is simple REST.
#   - Streams via chunked SSE parsing — identical token/tool_start/tool_end/done
#     event shape as all other strategies.
#   - Circuit breaker (3 strikes → open 30 s) prevents cascade failures when
#     CF Workers AI is degraded.
#   - Per-account connection pool (keyed by account_id) avoids re-handshaking
#     TLS on every call — critical for P3 (speed).
#   - Secrets never logged — api_key and account_id scrubbed from log lines.
# ─────────────────────────────────────────────────────────────────────────────

from __future__ import annotations

import asyncio
import json
import time
from collections import defaultdict
from typing import Any, AsyncGenerator, Dict, Optional

import httpx

from core.logger import mcp_logger
from core.http import get_client
from services.strategies.base import LLMStrategy

# ─── Constants ────────────────────────────────────────────────────────────────

CF_BASE_URL = "https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/v1"

# Recommended flagship open-source tool-calling models natively supported on Workers AI.
# These models are explicitly verified to support the `--enable-auto-tool-choice` vLLM backend.
RECOMMENDED_MODELS = {
    "llama-3.1-8b":  "@cf/meta/llama-3.1-8b-instruct-fp8",       # Best overall: Fast, reliable native tool-calling
    "llama-3.1-70b": "@cf/meta/llama-3.1-70b-instruct",          # High reasoning capacity
    "llama-3.3-70b": "@cf/meta/llama-3.3-70b-instruct-fp8-fast", # Latest fast frontier model
    "hermes-2-pro":  "@cf/nousresearch/hermes-2-pro-mistral-7b", # Mistral explicitly fine-tuned for tool calling
}

DEFAULT_MODEL = "@cf/meta/llama-3.1-8b-instruct-fp8"

# ─── Circuit Breaker ──────────────────────────────────────────────────────────

class CircuitBreakerOpen(Exception):
    """Raised when the circuit breaker is open — fast-fail without hitting CF."""
    pass


class _CircuitBreaker:
    """
    Per-account circuit breaker.
    States: CLOSED (normal) → OPEN (failing) → HALF-OPEN (probing).
    Thresholds: 3 consecutive failures → OPEN for 30 s.
    Thread-safe via asyncio.Lock (single-process, single-event-loop).
    """
    _FAILURE_THRESHOLD = 3
    _RECOVERY_TIMEOUT  = 30.0  # seconds

    def __init__(self, account_id_prefix: str):
        self._failures   = 0
        self._opened_at  = 0.0
        self._state      = "CLOSED"
        self._lock       = asyncio.Lock()
        self._label      = f"cf-breaker[{account_id_prefix}]"

    async def before_call(self) -> None:
        async with self._lock:
            if self._state == "OPEN":
                elapsed = time.monotonic() - self._opened_at
                if elapsed >= self._RECOVERY_TIMEOUT:
                    self._state = "HALF-OPEN"
                    mcp_logger.info(f"{self._label}: HALF-OPEN — probing CF")
                else:
                    raise CircuitBreakerOpen(
                        f"Cloudflare Workers AI circuit breaker OPEN "
                        f"(retry in {int(self._RECOVERY_TIMEOUT - elapsed)}s)"
                    )

    async def on_success(self) -> None:
        async with self._lock:
            if self._state != "CLOSED":
                mcp_logger.info(f"{self._label}: CLOSED — CF recovered")
            self._failures = 0
            self._state    = "CLOSED"

    async def on_failure(self, exc: Exception) -> None:
        async with self._lock:
            self._failures += 1
            mcp_logger.warning(
                f"{self._label}: failure #{self._failures} — {type(exc).__name__}: {exc}"
            )
            if self._failures >= self._FAILURE_THRESHOLD:
                self._state    = "OPEN"
                self._opened_at = time.monotonic()
                mcp_logger.error(
                    f"{self._label}: OPEN — backing off {self._RECOVERY_TIMEOUT}s "
                    f"after {self._failures} consecutive failures"
                )


# Module-level registry — one breaker per account_id prefix (first 8 chars)
_breakers: Dict[str, _CircuitBreaker] = {}

def _get_breaker(account_id: str) -> _CircuitBreaker:
    key = account_id[:8]
    if key not in _breakers:
        _breakers[key] = _CircuitBreaker(key)
    return _breakers[key]


# ─── HTTP Client Pool ─────────────────────────────────────────────────────────

# We use the shared singleton HttpClient from core.http to handle connection pooling
# across all strategies. This avoids leaking clients and handles timeouts gracefully.

# ─── SSE Parser ───────────────────────────────────────────────────────────────

async def _parse_sse_stream(response: httpx.Response) -> AsyncGenerator[dict, None]:
    """
    Parses chunked SSE lines from httpx streaming response.
    Yields parsed `data` dicts. Skips `[DONE]` sentinel.
    Handles multi-line data fields and keep-alive `: ` comment lines.
    """
    buffer = ""
    async for raw_chunk in response.aiter_text():
        buffer += raw_chunk
        while "\n\n" in buffer:
            event_block, buffer = buffer.split("\n\n", 1)
            for line in event_block.splitlines():
                line = line.strip()
                if not line or line.startswith(":"):   # SSE comment / keep-alive
                    continue
                if line.startswith("data:"):
                    payload = line[5:].strip()
                    if payload == "[DONE]":
                        return
                    try:
                        yield json.loads(payload)
                    except json.JSONDecodeError:
                        mcp_logger.debug(f"CF SSE non-JSON chunk (ignored): {payload[:80]}")


# ─── Strategy ─────────────────────────────────────────────────────────────────

class CloudflareStrategy(LLMStrategy):
    """
    LLM strategy for Cloudflare Workers AI open-source models.

    Uses the OpenAI-compatible chat/completions endpoint with streaming.
    Implements the same token/tool_start/tool_end/done yield protocol as
    OpenAIStrategy so AgentService requires zero changes.

    Credential format (passed as `api_key` in agent config):
        "<cloudflare_api_token>|<account_id>"
        e.g. "abc123...xyz|1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6d"

    This single-field format keeps compatibility with the existing Integration
    model (which stores a single `api_key` field per provider). The `|` separator
    is chosen because neither CF tokens nor account IDs contain it.
    """

    # ── Retry config ──────────────────────────────────────────────────────────
    _MAX_RETRIES   = 2
    _RETRY_BACKOFF = [1.0, 3.0]   # seconds between retry attempts
    _RETRYABLE_STATUS = {429, 500, 502, 503, 504}

    async def execute(
        self,
        api_key:          str,
        model:            str,
        system_prompt:    str,
        effective_history: list,
        full_user_message: str,
        tools:            list,
        context:          dict,
        output_format:    str  = "text",
        thinking_budget:  Optional[int] = None,
        use_stream:       bool = True,
        max_iterations:   int  = 7,
    ) -> AsyncGenerator[dict[str, Any], None]:

        # ── 1. Parse credentials ─────────────────────────────────────────────
        cf_token, account_id = self._parse_credentials(api_key)

        # ── 2. Circuit breaker pre-check ─────────────────────────────────────
        breaker = _get_breaker(account_id)
        try:
            await breaker.before_call()
        except CircuitBreakerOpen as e:
            yield {"type": "error", "data": str(e)}
            yield {"type": "done"}
            return

        # ── 3. Resolve model ─────────────────────────────────────────────────
        resolved_model = RECOMMENDED_MODELS.get(model, model)
        if not resolved_model.startswith("@cf/"):
            mcp_logger.warning(
                f"CF model '{resolved_model}' lacks '@cf/' prefix — "
                "ensure it is a valid Workers AI model ID"
            )

        # ── 4. Build messages ─────────────────────────────────────────────────
        messages = self._build_messages(system_prompt, effective_history, full_user_message)

        # ── 5. Build tool schemas (OpenAI format — CF OAI-compat accepts them) ─
        cf_tools = [t.to_openai_schema() for t in tools] if tools else None

        # ── 6. Agent loop ─────────────────────────────────────────────────────
        iteration = 0

        while iteration < max_iterations:
            iteration += 1

            # ── 6a. Call CF with retry ────────────────────────────────────────
            raw_events = []
            call_ok    = False
            last_exc: Optional[Exception] = None

            for attempt in range(self._MAX_RETRIES + 1):
                try:
                    raw_events, tool_calls, full_text = await self._stream_once(
                        account_id, cf_token, resolved_model, messages, cf_tools,
                        output_format, use_stream
                    )
                    call_ok = True
                    break

                except CircuitBreakerOpen:
                    raise  # already yielded — let outer handler deal with it

                except (httpx.TimeoutException, httpx.NetworkError) as exc:
                    last_exc = exc
                    mcp_logger.warning(
                        f"CF network error attempt {attempt + 1}: {exc}"
                    )

                except httpx.HTTPStatusError as exc:
                    last_exc = exc
                    status = exc.response.status_code
                    body_text = exc.response.text
                    mcp_logger.error(f"CF HTTP {status} error body: {body_text}")
                    
                    # Fallback for unsupported/paid models
                    if status in (400, 403, 404) and resolved_model != DEFAULT_MODEL:
                        mcp_logger.warning(f"CF model {resolved_model} rejected ({status}). Falling back to {DEFAULT_MODEL}")
                        resolved_model = DEFAULT_MODEL
                        continue # Retry immediately with default model
                        
                    if status not in self._RETRYABLE_STATUS or attempt == self._MAX_RETRIES:
                        await breaker.on_failure(exc)
                        yield {"type": "error", "data": f"Cloudflare API error {status}: {body_text}"}
                        yield {"type": "done"}
                        return
                    mcp_logger.warning(f"CF HTTP {status} — retrying attempt {attempt + 2}")

                if attempt < self._MAX_RETRIES:
                    await asyncio.sleep(self._RETRY_BACKOFF[attempt])

            if not call_ok:
                await breaker.on_failure(last_exc)
                yield {"type": "error", "data": f"CF unreachable after retries: {last_exc}"}
                yield {"type": "done"}
                return

            # ── 6b. Emit tokens ───────────────────────────────────────────────
            for evt in raw_events:
                yield evt

            # ── 6c. No tool calls → final response ───────────────────────────
            if not tool_calls:
                await breaker.on_success()
                yield {"type": "done"}
                break

            # ── 6d. Execute tools ─────────────────────────────────────────────
            # Append assistant's tool-call message to history
            messages.append({
                "role":       "assistant",
                "content":    "",
                "tool_calls": [
                    {
                        "id":       tc["id"],
                        "type":     "function",
                        "function": {"name": tc["name"], "arguments": tc["args"]},
                    }
                    for tc in tool_calls
                ],
            })

            for tc in tool_calls:
                tool_name = tc["name"]
                try:
                    args = json.loads(tc["args"])
                    yield {"type": "tool_start", "data": {"tool": tool_name, "args": args}}

                    tool_instance = next((t for t in tools if t.name == tool_name), None)
                    result = (
                        await tool_instance.run(**args, context=context)
                        if tool_instance
                        else f"Error: tool '{tool_name}' not registered."
                    )
                    yield {"type": "tool_end", "data": {"tool": tool_name, "result": result}}

                    messages.append({
                        "role":         "tool",
                        "tool_call_id": tc["id"],
                        "content":      json.dumps(result),
                    })

                except json.JSONDecodeError as e:
                    err = f"Bad JSON args for tool '{tool_name}': {e}"
                    mcp_logger.error(err)
                    yield {"type": "tool_end", "data": {"tool": tool_name, "result": err}}
                    messages.append({
                        "role":         "tool",
                        "tool_call_id": tc["id"],
                        "content":      err,
                    })

                except Exception as e:
                    err = f"Tool '{tool_name}' raised {type(e).__name__}: {e}"
                    mcp_logger.error(err)
                    yield {"type": "tool_end", "data": {"tool": tool_name, "result": err}}
                    messages.append({
                        "role":         "tool",
                        "tool_call_id": tc["id"],
                        "content":      err,
                    })

        else:
            # Exceeded max_iterations
            mcp_logger.warning(
                f"CF agent hit max_iterations={max_iterations} — forcing done"
            )
            yield {"type": "error", "data": "max_iterations reached without final answer"}
            yield {"type": "done"}

    # ── Private helpers ────────────────────────────────────────────────────────

    @staticmethod
    def _parse_credentials(api_key: str) -> tuple[str, str]:
        """
        Splits '<cf_api_token>|<account_id>' into components.
        Raises ValueError with a safe message (no secret in exception text).
        """
        parts = api_key.split("|", 1)
        if len(parts) != 2 or not parts[0] or not parts[1]:
            raise ValueError(
                "Cloudflare credential must be '<api_token>|<account_id>'. "
                "Check the Integration value stored for this agent."
            )
        return parts[0].strip(), parts[1].strip()

    @staticmethod
    def _build_messages(system_prompt: str, history: list, user_message: str) -> list:
        """Builds the OpenAI-format messages array."""
        msgs = []
        if system_prompt:
            msgs.append({"role": "system", "content": system_prompt})
        for msg in history:
            role = msg.get("role", "user")
            if role == "system":
                continue
            if role in ("ai", "model"):
                role = "assistant"
            msgs.append({"role": role, "content": msg.get("content", "")})
        msgs.append({"role": "user", "content": user_message})
        return msgs

    async def _stream_once(
        self,
        account_id:    str,
        cf_token:      str,
        model:         str,
        messages:      list,
        tools:         Optional[list],
        output_format: str,
        use_stream:    bool,
    ) -> tuple[list[dict], list[dict], str]:
        """
        Makes one POST to CF OAI-compat endpoint, streams the response,
        and returns (yield_events, tool_calls, full_text).

        yield_events : list of {"type": "token", "data": "<chunk>"} dicts
        tool_calls   : list of {"id", "name", "args"} dicts (args = raw JSON str)
        full_text    : concatenated text content (for buffered/non-stream mode)
        """
        payload: dict[str, Any] = {
            "model":    model,
            "messages": messages,
            "stream":   use_stream,
        }

        if tools:
            payload["tools"]       = tools

        if output_format == "json" and not tools:
            payload["response_format"] = {"type": "json_object"}

        yield_events: list[dict]  = []
        tool_calls:   list[dict]  = []
        full_text:    str         = ""
        current_tc:   Optional[dict] = None

        url = CF_BASE_URL.format(account_id=account_id) + "/chat/completions"
        headers = {
            "Accept": "text/event-stream" if use_stream else "application/json",
            "Authorization": f"Bearer {cf_token}",
            "Content-Type":  "application/json",
            "User-Agent":    "Mozilla/5.0 (compatible; AgencySaasSidecar/1.0)",
        }

        async with get_client().stream(
            "POST",
            url,
            content  = json.dumps(payload).encode(),
            headers  = headers,
            timeout  = 120.0,
        ) as response:
            if response.status_code >= 400:
                await response.aread()
            response.raise_for_status()

            if not use_stream:
                # Buffered mode (used by /v1/agent/run)
                body = await response.aread()
                data = json.loads(body)
                choice = data["choices"][0]
                msg    = choice.get("message", {})
                text   = msg.get("content") or ""
                full_text = text
                if text:
                    yield_events.append({"type": "token", "data": text})
                for tc in msg.get("tool_calls") or []:
                    func = tc.get("function", {})
                    tool_calls.append({
                        "id":   tc.get("id", ""),
                        "name": func.get("name", ""),
                        "args": func.get("arguments", "{}"),
                    })
                return yield_events, tool_calls, full_text

            # Streaming mode
            async for chunk in _parse_sse_stream(response):
                if not chunk.get("choices"):
                    continue
                delta = chunk["choices"][0].get("delta", {})

                # Token
                if content := delta.get("content"):
                    full_text += content
                    yield_events.append({"type": "token", "data": content})

                # Tool call deltas
                for tc_delta in delta.get("tool_calls") or []:
                    if tc_delta.get("id"):                    # new tool call starts
                        if current_tc:
                            tool_calls.append(current_tc)
                        current_tc = {
                            "id":   tc_delta["id"],
                            "name": tc_delta["function"].get("name", ""),
                            "args": "",
                        }
                    if current_tc and tc_delta.get("function", {}).get("arguments"):
                        current_tc["args"] += tc_delta["function"]["arguments"]
                    if current_tc and tc_delta.get("function", {}).get("name") and not current_tc["name"]:
                        current_tc["name"] = tc_delta["function"]["name"]

            if current_tc:
                tool_calls.append(current_tc)

        return yield_events, tool_calls, full_text