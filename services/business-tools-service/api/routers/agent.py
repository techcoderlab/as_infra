# ─────────────────────────────────────────────────────
# Module   : Agent Router
# Layer    : Presentation
# Pillar   : P1 Architecture, P2 Security, P3 Concurrency, P6 Resilience
# ─────────────────────────────────────────────────────

import json
import re
import asyncio
from fastapi import APIRouter, BackgroundTasks, Request, Depends, Header
from fastapi.responses import JSONResponse, StreamingResponse

from core.config import settings
from core.logger import mcp_logger
from services.agent import AgentService, AgentResult
from services.webhook_callback import WebhookCallbackService, WebhookDeliveryError
from api.dependencies import verify_hmac_signature
from api.schemas import AgentEnqueueRequest


router = APIRouter(prefix="/v1/agent", tags=["agent"])

# ─────────────────────────────────────────────────────
# Shared Resources (process-level singletons)
# ─────────────────────────────────────────────────────

# Semaphore to bound concurrent background agent jobs
# Prevents unbounded LLM stream explosion under load
_agent_semaphore = asyncio.Semaphore(settings.MAX_CONCURRENT_AGENT_JOBS)

# Shared webhook delivery service
_webhook_service = WebhookCallbackService()


# ─────────────────────────────────────────────────────
# 1. POST /run — Sync buffered response (for Laravel Jobs)
# ─────────────────────────────────────────────────────

@router.post("/run", dependencies=[Depends(verify_hmac_signature)])
async def agent_run(request: Request):
    """Sync endpoint: Buffers the stream and returns final JSON (for Laravel Jobs)"""
    body = await request.json()
    service = AgentService()

    try:
        result = await service.run_and_collect(body)

        if result.status == "failed":
            raise Exception(result.error)

        # Extract JSON from response
        json_match = re.search(r'\{[\s\S]*\}', result.response)
        response_json = json_match.group(0) if json_match else result.response

        return {
            "success": True,
            "response": response_json,
            "raw_content": result.raw_content,
            "thoughtStream": result.thought_stream
        }

    except Exception as e:
        mcp_logger.error(f"Agent Run Error: {str(e)}")
        return JSONResponse(status_code=500, content={
            "success": False,
            "isError": True,
            "error": str(e),
            "thoughtStream": result.thought_stream if 'result' in dir() else []
        })


# ─────────────────────────────────────────────────────
# 2. POST /chat — SSE stream (for Frontend Chat)
# ─────────────────────────────────────────────────────

@router.post("/chat", dependencies=[Depends(verify_hmac_signature)])
async def agent_chat(request: Request):
    """Async endpoint: Returns SSE stream (for Frontend Chat)"""
    body = await request.json()
    service = AgentService()

    async def event_generator():
        try:
            async for event in service.run_stream(body):
                yield f"data: {json.dumps(event)}\n\n"
        except Exception as e:
            mcp_logger.error(f"Agent Chat Stream Error: {str(e)}")
            yield f"data: {json.dumps({'type': 'error', 'data': str(e)})}\n\n"

    return StreamingResponse(
        event_generator(),
        media_type="text/event-stream"
    )


# ─────────────────────────────────────────────────────
# 3. POST /enqueue — Timeout-immune async processing
#    Decoupled: works with Laravel, n8n, or any 3rd-party API
# ─────────────────────────────────────────────────────

@router.post("/enqueue")
async def agent_enqueue(request: Request, background_tasks: BackgroundTasks):
    """
    Accepts an agent job, returns immediately, processes in background,
    and delivers results via webhook callback to any configured endpoint.

    Backward compatible with Laravel: accepts legacy 'webhook_url' field.
    New callers should use the 'callback' config for full control.
    """
    # P2 Security: Verify HMAC signature
    await verify_hmac_signature(request)

    body = await request.json()

    # Validate with Pydantic schema
    try:
        enqueue_request = AgentEnqueueRequest(**body)
    except Exception as e:
        return JSONResponse(status_code=422, content={
            "status": "rejected",
            "error": str(e)
        })

    # Resolve callback config: new 'callback' object OR legacy 'webhook_url'
    if enqueue_request.callback:
        callback_config = enqueue_request.callback
    else:
        # TRADE-OFF: P1 Architecture — backward compat shim for Laravel callers
        # that still send bare 'webhook_url' without the new 'callback' config.
        # This will be deprecated once all callers migrate.
        from api.schemas import CallbackConfig
        callback_config = CallbackConfig(url=enqueue_request.webhook_url)

    # Dispatch to background with semaphore protection
    background_tasks.add_task(
        _run_agent_background,
        job_id=enqueue_request.job_id,
        payload=body,
        callback_config=callback_config,
    )

    # RETURN IMMEDIATELY — caller (Laravel/3rd-party) is freed here
    return {"status": "accepted", "job_id": enqueue_request.job_id}


async def _run_agent_background(
    job_id: str,
    payload: dict,
    callback_config,
) -> None:
    """
    Background processing logic for enqueued agent jobs.
    Protected by semaphore to prevent unbounded concurrency.

    Parameters:
        job_id: Correlation ID for logging and callback.
        payload: Full request payload for AgentService.
        callback_config: CallbackConfig with URL, signing, headers, etc.
    """
    # P3 Concurrency: Acquire semaphore slot before starting LLM work
    async with _agent_semaphore:
        mcp_logger.info(f"[AgentBackground] job_id={job_id} | STARTED")

        service = AgentService()
        result = await service.run_and_collect(payload)

        mcp_logger.info(
            f"[AgentBackground] job_id={job_id} | status={result.status} | "
            f"duration={result.duration_ms}ms"
        )

    # Deliver callback (outside semaphore — don't hold the slot during HTTP)
    try:
        await _webhook_service.deliver(
            url=str(callback_config.url),
            job_id=job_id,
            status=result.status,
            data=result.to_callback_payload(),
            method=callback_config.method,
            signing_secret=callback_config.signing_secret,
            custom_headers=callback_config.headers,
            timeout=callback_config.timeout_seconds,
            max_retries=callback_config.max_retries,
        )
    except WebhookDeliveryError as e:
        # Already dead-letter logged inside WebhookCallbackService
        mcp_logger.error(f"[AgentBackground] job_id={job_id} | Webhook delivery failed: {e}")
    except ValueError as e:
        # SSRF prevention — URL not in allowlist
        mcp_logger.error(f"[AgentBackground] job_id={job_id} | Callback URL rejected: {e}")

