# ─────────────────────────────────────────────────────────────────────────────
# Module   : CloudflareStrategy
# Layer    : Application › LLM Strategies
# Pattern  : Strategy (GoF) — matches OpenAIStrategy / GeminiStrategy / AnthropicStrategy
#
# FIXED against https://developers.cloudflare.com/workers-ai/ :
#   1. Removed deprecated model IDs that caused 404:
#        @cf/meta/llama-3.1-70b-instruct, @cf/nousresearch/hermes-2-pro-mistral-7b
#   2. 401/403 are auth errors → fail fast with a clear message instead of
#      silently swapping models (old behavior masked real credential problems).
#   3. 404/400 on a non-default model → exactly ONE fallback to DEFAULT_MODEL.
#   4. Streaming tool-call deltas reassembled by `index` → parallel tool calls
#      are no longer merged/corrupted.
#   5. `tool_choice: "auto"` sent explicitly (per CF function-calling docs).
#   6. response_format (JSON mode) only sent for models that support it.
#   7. list_models() diagnostic helper to check the live catalog.
# ─────────────────────────────────────────────────────────────────────────────

from __future__ import annotations

import asyncio
import json
import time
from typing import Any, AsyncGenerator, Dict, Optional

import httpx

from core.logger import mcp_logger
from core.http import get_client
from services.strategies.base import LLMStrategy

# ─── Constants ────────────────────────────────────────────────────────────────

CF_BASE_URL          = "https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/v1"
CF_MODELS_SEARCH_URL = "https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/models/search"

# Verified against https://developers.cloudflare.com/workers-ai/models/
# ("Function calling" property on each model page).
TOOL_CALLING_MODELS = {
    "llama-3.3-70b": "@cf/meta/llama-3.3-70b-instruct-fp8-fast",  # flagship tool-calling
    "gpt-oss-120b":  "@cf/openai/gpt-oss-120b",                    # strongest (needs Paid plan)
    "llama-3.1-8b":  "@cf/meta/llama-3.1-8b-instruct-fp8",         # fast/cheap tool-calling
}
GENERAL_MODELS = {
    "llama-3.1-8b-fast": "@cf/meta/llama-3.1-8b-instruct-fast",
    "llama-3.2-3b":      "@cf/meta/llama-3.2-3b-instruct",
    "qwq-32b":           "@cf/qwen/qwq-32b",                       # reasoning, no tools
}

RECOMMENDED_MODELS = {
    **TOOL_CALLING_MODELS,
    **GENERAL_MODELS,
    # Legacy aliases → nearest live replacement (old agent configs keep working)
    "llama-3.1-70b": "@cf/meta/llama-3.3-70b-instruct-fp8-fast",   # old model deprecated
    "hermes-2-pro":  "@cf/meta/llama-3.1-8b-instruct-fp8",         # old model deprecated
}

DEFAULT_MODEL = "@cf/meta/llama-3.1-8b-instruct-fp8"

# JSON mode (response_format) is only supported on some models — whitelisting
# avoids 400s from models that reject the parameter.
JSON_MODE_SUPPORTED = {
    "@cf/meta/llama-3.3-70b-instruct-fp8-fast",
    "@cf/meta/llama-3.1-8b-instruct-fp8",
    "@cf/openai/gpt-oss-120b",
    "@cf/openai/gpt-oss-20b",
}

# ─── Circuit Breaker ──────────────────────────────────────────────────────────
# (unchanged from your version)

class CircuitBreakerOpen(Exception):
    pass


class _CircuitBreaker:
    _FAILURE_THRESHOLD = 3
    _RECOVERY_TIMEOUT  = 30.0

    def __init__(self, account_id_prefix: str):
        self._failures  = 0
        self._opened_at = 0.0
        self._state     = "CLOSED"
        self._lock      = asyncio.Lock()
        self._label     = f"cf-breaker[{account_id_prefix}]"

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
            mcp_logger.warning(f"{self._label}: failure #{self._failures} — {type(exc).__name__}: {exc}")
            if self._failures >= self._FAILURE_THRESHOLD:
                self._state     = "OPEN"
                self._opened_at = time.monotonic()
                mcp_logger.error(
                    f"{self._label}: OPEN — backing off {self._RECOVERY_TIMEOUT}s "
                    f"after {self._failures} consecutive failures"
                )


_breakers: Dict[str, _CircuitBreaker] = {}

def _get_breaker(account_id: str) -> _CircuitBreaker:
    key = account_id[:8]
    if key not in _breakers:
        _breakers[key] = _CircuitBreaker(key)
    return _breakers[key]


# ─── SSE Parser ───────────────────────────────────────────────────────────────

async def _parse_sse_stream(response: httpx.Response) -> AsyncGenerator[dict, None]:
    """Parses chunked SSE `data:` payloads; skips keep-alives and [DONE]."""
    buffer = ""
    async for raw_chunk in response.aiter_text():
        buffer += raw_chunk
        while "\n\n" in buffer:
            event_block, buffer = buffer.split("\n\n", 1)
            for line in event_block.splitlines():
                line = line.strip()
                if not line or line.startswith(":"):
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
    Credential format: "<cf_api_token>|<account_id>"
    Token MUST have Account → Workers AI → Edit permission and belong to
    the same account as <account_id>.
    """

    _MAX_RETRIES      = 2
    _RETRY_BACKOFF    = [1.0, 3.0]
    _RETRYABLE_STATUS = {429, 500, 502, 503, 504}

    async def execute(
        self,
        api_key:           str,
        model:             str,
        system_prompt:     str,
        effective_history: list,
        full_user_message: str,
        tools:             list,
        context:           dict,
        output_format:     str = "text",
        thinking_budget:   Optional[int] = None,
        use_stream:        bool = True,
        max_iterations:    int  = 7,
        temperature:      Optional[float] = None,   # ← add
    ) -> AsyncGenerator[dict[str, Any], None]:

        # 1. Parse credentials
        cf_token, account_id = self._parse_credentials(api_key)

        # 2. Circuit breaker pre-check
        breaker = _get_breaker(account_id)
        try:
            await breaker.before_call()
        except CircuitBreakerOpen as e:
            yield {"type": "error", "data": str(e)}
            yield {"type": "done"}
            return

        # 3. Resolve model
        resolved_model = RECOMMENDED_MODELS.get(model, model)
        fallback_used  = False

        # 4-5. Messages + tool schemas (OpenAI format — accepted by CF OAI-compat)
        messages = self._build_messages(system_prompt, effective_history, full_user_message)
        cf_tools = [t.to_openai_schema() for t in tools] if tools else None

        # 6. Agent loop
        iteration = 0
        while iteration < max_iterations:
            iteration += 1

            raw_events: list[dict] = []
            tool_calls: list[dict] = []
            full_text   = ""
            call_ok     = False
            last_exc: Optional[Exception] = None

            for attempt in range(self._MAX_RETRIES + 1):
                try:
                    raw_events, tool_calls, full_text = await self._stream_once(
                        account_id, cf_token, resolved_model, messages, cf_tools,
                        output_format, use_stream, temperature
                    )
                    call_ok = True
                    break

                except (httpx.TimeoutException, httpx.NetworkError) as exc:
                    last_exc = exc
                    mcp_logger.warning(f"CF network error attempt {attempt + 1}: {exc}")

                except httpx.HTTPStatusError as exc:
                    status    = exc.response.status_code
                    body_text = exc.response.text[:400]
                    mcp_logger.error(f"CF HTTP {status} error body: {body_text}")

                    # ① 401/403 = credentials / token permissions — NOT a model
                    #    problem. Retrying or switching models cannot fix it.
                    if status in (401, 403):
                        await breaker.on_failure(exc)
                        yield {"type": "error", "data": (
                            f"Cloudflare auth failed (HTTP {status}). Ensure the API "
                            "token has 'Workers AI → Edit' on the token's account and "
                            f"api_key is '<token>|<account_id>'. Details: {body_text}"
                        )}
                        yield {"type": "done"}
                        return

                    # ② 404 (or 400) = unknown/deprecated model → ONE fallback
                    if (status in (400, 404) and not fallback_used
                            and resolved_model != DEFAULT_MODEL):
                        fallback_used = True
                        mcp_logger.warning(
                            f"CF model {resolved_model} rejected ({status}) — "
                            f"falling back to {DEFAULT_MODEL}"
                        )
                        resolved_model = DEFAULT_MODEL
                        continue

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

            # Emit tokens
            for evt in raw_events:
                yield evt

            # No tool calls → final response
            if not tool_calls:
                await breaker.on_success()
                yield {"type": "done"}
                break

            # Append assistant tool-call message (preserve real content — some
            # models emit text before tool calls; keeping it maintains context)
            messages.append({
                "role":    "assistant",
                "content": full_text,          # was: full_text if full_text else None
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
                    messages.append({"role": "tool", "tool_call_id": tc["id"], "content": err})

                except Exception as e:
                    err = f"Tool '{tool_name}' raised {type(e).__name__}: {e}"
                    mcp_logger.error(err)
                    yield {"type": "tool_end", "data": {"tool": tool_name, "result": err}}
                    messages.append({"role": "tool", "tool_call_id": tc["id"], "content": err})
        else:
            mcp_logger.warning(f"CF agent hit max_iterations={max_iterations} — forcing done")
            yield {"type": "error", "data": "max_iterations reached without final answer"}
            yield {"type": "done"}

    # ── Private helpers ────────────────────────────────────────────────────────

    @staticmethod
    async def list_models(api_key: str, task: Optional[str] = "Text Generation") -> list[dict]:
        """
        Diagnostic helper — returns the LIVE Workers AI catalog for this account.
        Use this whenever a 404 appears to verify current model IDs
        (catalog: https://developers.cloudflare.com/workers-ai/models/).
        """
        token, account = CloudflareStrategy._parse_credentials(api_key)
        params: dict[str, Any] = {"per_page": 100}
        if task:
            params["task"] = task
        resp = await get_client().get(   # adapt if your wrapper lacks .get()
            CF_MODELS_SEARCH_URL.format(account_id=account),
            headers={"Authorization": f"Bearer {token}"},
            params=params,
            timeout=30.0,
        )
        resp.raise_for_status()
        return resp.json().get("result", [])

    @staticmethod
    def _parse_credentials(api_key: str) -> tuple[str, str]:
        parts = api_key.split("|", 1)
        if len(parts) != 2 or not parts[0] or not parts[1]:
            raise ValueError(
                "Cloudflare credential must be '<api_token>|<account_id>'. "
                "Check the Integration value stored for this agent."
            )
        return parts[0].strip(), parts[1].strip()

    @staticmethod
    def _build_messages(system_prompt: str, history: list, user_message: str) -> list:
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


        # ── Content normalization (CF schema compliance) ─────────────────────────
    @staticmethod
    def _normalize_content(content: Any) -> str:
        """
        CF /ai/v1/chat/completions REQUIRES messages[].content to be a string
        (JSON schema: oneOf {prompt} | {messages[{content: string}]}).
        Multi-provider histories / the memory graph may carry content as
        content-part arrays (OpenAI vision or Anthropic style) — flatten them.
        """
        if content is None:
            return ""
        if isinstance(content, str):
            return content
        if isinstance(content, list):
            parts = []
            for part in content:
                if isinstance(part, str):
                    parts.append(part)
                elif isinstance(part, dict) and part.get("text"):
                    parts.append(str(part["text"]))
            return "\n".join(p for p in parts if p)
        if isinstance(content, dict):
            return str(content.get("text")) if content.get("text") else json.dumps(content)
        return str(content)

    @staticmethod
    def _normalize_message(msg: dict) -> dict:
        """Returns a CF-safe message: string content, string tool-call args."""
        out: dict = {"role": msg.get("role", "user"),
                     "content": CloudflareStrategy._normalize_content(msg.get("content"))}
        if msg.get("tool_call_id") is not None:
            out["tool_call_id"] = msg["tool_call_id"]
        if msg.get("name"):
            out["name"] = msg["name"]
        if msg.get("tool_calls"):
            tcs = []
            for tc in msg["tool_calls"]:
                fn   = tc.get("function", {}) or {}
                args = fn.get("arguments", "{}")
                if not isinstance(args, str):        # some backends return dicts
                    args = json.dumps(args)
                tcs.append({
                    "id":       tc.get("id", ""),
                    "type":     "function",
                    "function": {"name": fn.get("name", ""), "arguments": args},
                })
            out["tool_calls"] = tcs
        return out

    async def _stream_once(
        self,
        account_id:    str,
        cf_token:      str,
        model:         str,
        messages:      list,
        tools:         Optional[list],
        output_format: str,
        use_stream:    bool,
        temperature: Optional[float] = None
    ) -> tuple[list[dict], list[dict], str]:
        payload: dict[str, Any] = {
            "model":    model,
            # "messages": messages,
            "messages": [self._normalize_message(m) for m in messages],
            "stream":   use_stream,
        }

        if temperature is not None:
            payload["temperature"] = temperature

        if tools:
            payload["tools"]       = tools
            payload["tool_choice"] = "auto"   # explicit per CF function-calling docs
        if output_format == "json" and not tools and model in JSON_MODE_SUPPORTED:
            payload["response_format"] = {"type": "json_object"}

        yield_events: list[dict] = []
        full_text:    str        = ""
        tc_acc:       dict[int, dict] = {}   # keyed by `index` → parallel-safe

        url     = CF_BASE_URL.format(account_id=account_id) + "/chat/completions"
        headers = {
            "Authorization": f"Bearer {cf_token}",
            "Content-Type":  "application/json",
            "Accept":        "text/event-stream" if use_stream else "application/json",
        }

        async with get_client().stream(
            "POST", url,
            content = json.dumps(payload).encode(),
            headers = headers,
            timeout = 120.0,
        ) as response:
            if response.status_code >= 400:
                await response.aread()   # buffer error body so .text works after raise
            response.raise_for_status()

            if not use_stream:
                data = json.loads(await response.aread())
                msg  = (data.get("choices") or [{}])[0].get("message", {})
                text = msg.get("content") or ""
                full_text = text
                if text:
                    yield_events.append({"type": "token", "data": text})
                for tc in msg.get("tool_calls") or []:
                    fn = tc.get("function", {})
                    tool_calls_list = msg.get("tool_calls") or []
                    tool_calls_list = tool_calls_list  # noqa
                    yield_events  # noqa
                    tool_calls_collected = {
                        "id":   tc.get("id", ""),
                        "name": fn.get("name", ""),
                        "args": fn.get("arguments", "{}"),
                    }
                    _ = tool_calls_collected
                tool_calls: list[dict] = [
                    {
                        "id":   tc.get("id", ""),
                        "name": (tc.get("function") or {}).get("name", ""),
                        "args": (tc.get("function") or {}).get("arguments", "{}"),
                    }
                    for tc in (msg.get("tool_calls") or [])
                ]
                return yield_events, tool_calls, full_text

            # Streaming mode
            async for chunk in _parse_sse_stream(response):
                choices = chunk.get("choices") or []
                if not choices:
                    continue
                delta = choices[0].get("delta") or {}

                # if content := delta.get("content"):
                #     full_text += content
                #     yield_events.append({"type": "token", "data": content})



                if content := delta.get("content"):
                    if not isinstance(content, str):
                        # CF quirk: numeric literals in JSON-mode output can arrive
                        # as JSON numbers instead of strings. Coerce and log once.
                        mcp_logger.warning(
                            f"CF non-string content delta: {content!r} "
                            f"({type(content).__name__}) — coerced to str"
                        )
                        content = str(content)
                    full_text += content
                    yield_events.append({"type": "token", "data": content})

                # Tool-call deltas — reassemble by `index` so PARALLEL tool
                # calls aren't merged into one (bug in the old id-based logic)
                for tc_delta in delta.get("tool_calls") or []:
                    idx = tc_delta.get("index", 0)
                    acc = tc_acc.setdefault(idx, {"id": "", "name": "", "args": ""})
                    if tc_delta.get("id"):
                        acc["id"] = tc_delta["id"]
                    fn = tc_delta.get("function") or {}
                    if fn.get("name"):
                        acc["name"] = fn["name"] if not acc["name"] else acc["name"] + fn["name"]
                    if fn.get("arguments"):
                        acc["args"] += str(fn["arguments"] or "")

            tool_calls = [tc_acc[i] for i in sorted(tc_acc) if tc_acc[i]["name"]]

        return yield_events, tool_calls, full_text