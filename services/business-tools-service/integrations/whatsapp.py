from typing import Any, Dict, Optional
from core.http import HttpClient
from core.config import settings
from core.logger import wa_logger
from core.rate_limiter import rate_limiter_registry as registry
import asyncio

class WhatsAppClient:
    def __init__(
        self, 
        phone_number_id: str, 
        access_token: str, 
        http_client: Optional[HttpClient] = None
    ):
        """
        Multi-tenant WhatsApp Client.
        
        :param http_client: Pass a shared HttpClient instance for high performance.
        If None, a new one is created (slower for batch processing).
        """
        self.phone_number_id = phone_number_id
        self.access_token = access_token
        self.base = settings.WA_API_BASE
        
        # Resource sharing: Use passed client or create a local one
        self.http = http_client if http_client else HttpClient()

    async def _send_request(self, payload: Dict[str, Any], idempotency_key: str = None) -> Dict[str, Any]:
        """Internal helper to handle rate limiting and headers"""
        
        # 1. Rate Limiting Check (Persistent per Tenant)
        bucket = registry.get_bucket(self.phone_number_id, settings.WA_RATE_LIMIT_PER_MINUTE)
        wait_time = bucket.consume()
        
        if wait_time > 0:
            wa_logger.info(f"Tenant {self.phone_number_id} rate limited. Smoothing burst for {wait_time:.2f}s")
            await asyncio.sleep(wait_time)

        # 2. Prepare Request
        url = f"{self.base}/{self.phone_number_id}/messages"
        headers = {
            "Authorization": f"Bearer {self.access_token}",
            "Content-Type": "application/json",
        }
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key

        # 3. Execute        
        try:
            resp = await self.http.post(url, headers=headers, json=payload)
            data = resp.json()
            return data
            
        except Exception as e:
            # This will now catch the error from core/http.py
            # AND it will print the specific error message from Meta (e.g. "Invalid Parameter")
            wa_logger.error(f"Meta API Failed for {self.phone_number_id}: {str(e)}")
            raise e

    async def send_template(self, to_number: str, template_name: str, language: str = "en_US") -> Dict[str, Any]:
        payload = {
            "messaging_product": "whatsapp",
            "to": to_number,
            "type": "template",
            "template": {
                "name": template_name,
                "language": {"code": language}
            }
        }
        wa_logger.info(f"Sending template '{template_name}' to {to_number} via {self.phone_number_id}")
        return await self._send_request(payload)

    async def send_text(self, to_number: str, body: str) -> Dict[str, Any]:
        payload = {
            "messaging_product": "whatsapp",
            "to": to_number,
            "type": "text",
            "text": {"preview_url": False, "body": body},
        }
        wa_logger.info(f"Sending text to {to_number} via {self.phone_number_id}")
        return await self._send_request(payload)