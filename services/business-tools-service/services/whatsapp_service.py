from core.logger import wa_logger
from core.http import get_client
from integrations.whatsapp import WhatsAppClient
from api.schemas import AutomationPayload

async def process_whatsapp_message(payload: AutomationPayload):
    # 1. Capture Trace ID
    tid = payload.message.trace_id or "N/A"
    
    wa_logger.info(f"[{tid}] ⏳ STARTED processing for {payload.message.recipient_phone}")

    try:
        # 2. OPTIMIZATION: Get the singleton client directly.
        # No need to pass it from the route anymore.
        shared_client = get_client()

        wa_client = WhatsAppClient(
            phone_number_id=payload.config.phone_number_id,
            access_token=payload.config.access_token,
            http_client=shared_client # Passing the robust wrapper
        )
        
        if payload.message.type == "template":
            if not payload.message.template:
                raise ValueError("Template details missing")
                
            await wa_client.send_template(
                to_number=payload.message.recipient_phone,
                template_name=payload.message.template.name,
                language=payload.message.template.language
            )
            
        elif payload.message.type == "text":
            if not payload.message.text_body:
                raise ValueError("Text body missing")
                
            await wa_client.send_text(
                to_number=payload.message.recipient_phone,
                body=payload.message.text_body
            )

        wa_logger.info(f"[{tid}] ✅ COMPLETED successfully")
            
    except Exception as e:
        wa_logger.error(f"[{tid}] ❌ FAILED: {str(e)}")
