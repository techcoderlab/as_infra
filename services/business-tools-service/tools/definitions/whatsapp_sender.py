from pydantic import BaseModel, Field
from typing import Optional
from tools.base import BaseTool
from core.http import get_client
from core.logger import mcp_logger
from integrations.whatsapp import WhatsAppClient
from core.decorators import tool_timeout

# 1. Define Arguments Schema
class WhatsAppSendArgs(BaseModel):
    recipient_phone: str = Field(..., description="The phone number to send the message to (e.g., '15550001234').")
    message_body: Optional[str] = Field(None, description="The text content to send. Required if template_name is not provided.")
    template_name: Optional[str] = Field(None, description="The name of the WhatsApp template to send. Required if message_body is not provided.")
    template_language: str = Field("en_US", description="Language code for the template (default: 'en_US').")

# 2. Define Tool Logic
class WhatsAppSenderTool(BaseTool):
    name = "whatsapp_sender"
    description = (
        "Sends a WhatsApp message (text or template) to a user. "
        "Useful for notifying leads, sending alerts, or responding to queries via WhatsApp. "
        "Requires explicit user confirmation before sending."
    )
    args_schema = WhatsAppSendArgs

    @tool_timeout(seconds=15)
    async def run(
        self, 
        recipient_phone: str, 
        message_body: str = None, 
        template_name: str = None, 
        template_language: str = "en_US", 
        context: dict = None
    ):
        context = context or {}
        # 1. Security & Config Retrieval
        tool_configs = context.get('tool_configs', {})
        
        # Debugging: Log available keys
        mcp_logger.info(f"[WhatsAppSender] Available Config Keys: {list(tool_configs.keys())}")

        # Check 'whatsapp_sender' (self.name) OR 'whatsapp' (provider name)
        wa_config = tool_configs.get(self.name) or tool_configs.get('whatsapp')
        
        mcp_logger.info(f"[WhatsAppSender] Config Found: {wa_config is not None}")
        if not wa_config:
            return {
                "isError": True,
                "content": {
                    "type": "text", 
                    "text": "Configuration Error: No WhatsApp credentials found. Please configure the tool in the agent settings."
                }
            }

        api_key = wa_config.get('api_key')
        phone_id = wa_config.get('phone_id')

        if not api_key or not phone_id:
            return {
                "isError": True,
                "content": {
                    "type": "text", 
                    "text": "Configuration Error: Missing 'api_key' or 'phone_id' in WhatsApp configuration."
                }
            }

        # 2. Validation
        if not message_body and not template_name:
            return {
                "isError": True,
                "content": {
                    "type": "text", 
                    "text": "Usage Error: You must provide either 'message_body' or 'template_name'."
                }
            }

        # 3. Execution
        try:
            # Reusing the robust WhatsAppClient from integrations
            client = WhatsAppClient(
                phone_number_id=phone_id,
                access_token=api_key,
                http_client=get_client() # Use shared pool for performance
            )

            mcp_logger.info(f"[WhatsAppSender] Sending to {recipient_phone}")

            if template_name:
                response = await client.send_template(
                    to_number=recipient_phone,
                    template_name=template_name,
                    language=template_language
                )
            else:
                response = await client.send_text(
                    to_number=recipient_phone,
                    body=message_body
                )

            # Check for Meta API errors in response
            # Note: WhatsAppClient raises exceptions on HTTP errors, but we check specific keys just in case
            if "error" in response:
                error_msg = response['error'].get('message', 'Unknown Error')
                raise Exception(f"Meta API Error: {error_msg}")

            return {
                "content": {
                    "type": "text",
                    "text": f"SUCCESS: Message sent to {recipient_phone}."
                }
            }

        except Exception as e:
            mcp_logger.error(f"[WhatsAppSender Error] {str(e)}")
            return {
                "isError": True,
                "content": {
                    "type": "text", 
                    "text": f"Failed to send message: {str(e)}"
                }
            }
