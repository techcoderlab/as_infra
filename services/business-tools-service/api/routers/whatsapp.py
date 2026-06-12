from fastapi import APIRouter, BackgroundTasks, Depends, HTTPException
from api.schemas import AutomationPayload
from api.dependencies import verify_hmac_signature
from services.whatsapp_service import process_whatsapp_message

router = APIRouter(prefix="/v1/wa", tags=["whatsapp"])

@router.post("/send", status_code=202, dependencies=[Depends(verify_hmac_signature)])
async def send_message(payload: AutomationPayload, background_tasks: BackgroundTasks):
    """
    Receives a WhatsApp request, queues it, and returns 202 immediately.
    """
    # 1. Validation Logic
    if payload.message.type == "template" and not payload.message.template:
        raise HTTPException(status_code=400, detail="Template object required for type 'template'")
    if payload.message.type == "text" and not payload.message.text_body:
        raise HTTPException(status_code=400, detail="text_body required for type 'text'")

    # 2. Queue the task
    # Note: We don't need to pass the client here anymore; the task grabs it itself.
    background_tasks.add_task(process_whatsapp_message, payload)

    return {
        "status": "queued",
        "message": "Request accepted and processing in background",
        "recipient": payload.message.recipient_phone,
        "trace_id": payload.message.trace_id
    }
