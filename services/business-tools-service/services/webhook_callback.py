# ─────────────────────────────────────────────────────
# Module   : WebhookCallbackService
# Layer    : Infrastructure
# Pillar   : P1 Architecture, P2 Security, P6 Resilience
# ─────────────────────────────────────────────────────

import json
import time
import hmac
import hashlib
from urllib.parse import urlparse
from typing import Optional, Dict, Any

from core.config import settings
from core.logger import mcp_logger
from core.http import get_client


class WebhookDeliveryError(Exception):
    """Raised when webhook delivery fails after all retries."""
    pass


class WebhookCallbackService:
    """
    Generic, caller-agnostic webhook delivery service.

    Replaces the old 'notify_laravel()' function with a decoupled implementation
    that works with any 3rd-party API or internal service.

    Features:
        - Per-caller HMAC-SHA256 signing (or fallback to env secret)
        - Configurable URL allowlist for SSRF prevention
        - Retry with exponential backoff (via HttpClient)
        - Dead-letter logging on final delivery failure
        - Structured correlation via job_id

    Parameters:
        None — all config comes from CallbackConfig per request.
    """

    def __init__(self):
        self._allowed_domains = self._parse_allowed_domains()

    def _parse_allowed_domains(self) -> set:
        """
        Parse comma-separated ALLOWED_CALLBACK_DOMAINS from env.
        Empty string means allow all (for dev / migration period).

        Returns:
            set: Set of allowed domain strings, or empty set for allow-all.
        """
        raw = settings.ALLOWED_CALLBACK_DOMAINS.strip()
        if not raw:
            return set()  # Empty = allow all
        return {d.strip().lower() for d in raw.split(",") if d.strip()}

    def _validate_callback_url(self, url: str) -> None:
        """
        Validate that the callback URL is in the allowlist.
        Prevents SSRF attacks where a caller could exfiltrate data
        to an attacker-controlled endpoint.

        Parameters:
            url: The callback URL to validate.

        Raises:
            ValueError: If the URL's domain is not in the allowlist.
        """
        if not self._allowed_domains:
            return  # Allow all when no allowlist configured

        parsed = urlparse(str(url))
        domain = parsed.hostname or ""

        if domain.lower() not in self._allowed_domains:
            raise ValueError(
                f"Callback domain '{domain}' is not in the allowed list. "
                f"Allowed: {', '.join(self._allowed_domains)}"
            )

    def _sign_payload(self, payload_bytes: bytes, secret: str) -> tuple:
        """
        Generate HMAC-SHA256 signature for the payload.

        Parameters:
            payload_bytes: The raw bytes of the JSON payload.
            secret: The signing secret.

        Returns:
            tuple: (signature_hex, timestamp_str)
        """
        timestamp = str(time.time())
        signature = hmac.new(
            secret.encode(),
            timestamp.encode() + payload_bytes,
            hashlib.sha256
        ).hexdigest()
        return signature, timestamp

    async def deliver(
        self,
        url: str,
        job_id: str,
        status: str,
        data: Dict[str, Any],
        method: str = "POST",
        signing_secret: Optional[str] = None,
        custom_headers: Optional[Dict[str, str]] = None,
        timeout: float = 10.0,
        max_retries: int = 3,
    ) -> bool:
        """
        Deliver a webhook callback to the specified URL.

        Parameters:
            url: Callback endpoint URL.
            job_id: Correlation ID for the job.
            status: Job status (completed, failed).
            data: Result payload to deliver.
            method: HTTP method (POST or PUT).
            signing_secret: HMAC secret for signing. Falls back to env token if None.
            custom_headers: Additional headers from the caller's config.
            timeout: HTTP timeout for this delivery.
            max_retries: Number of retries on failure.

        Returns:
            bool: True if delivery succeeded.

        Raises:
            WebhookDeliveryError: If delivery fails after all retries.
            ValueError: If callback URL is not in the allowlist.
        """
        # P2 Security: SSRF prevention
        self._validate_callback_url(url)

        # Build payload
        payload_dict = {
            "job_id": job_id,
            "status": status,
            "data": data
        }
        payload_bytes = json.dumps(payload_dict).encode()

        # Determine signing secret: per-caller > env fallback
        # DATA: SECRET — never log the actual secret value
        effective_secret = signing_secret or settings.MCP_SIDECAR_CRM_SERVICE_TOKEN
        
        # Build headers
        headers = {
            "Content-Type": "application/json",
        }

        # Sign the payload if we have a secret
        if effective_secret:
            signature, timestamp = self._sign_payload(payload_bytes, effective_secret)
            headers["X-Signature"] = signature
            headers["X-Timestamp"] = timestamp

        # Merge caller's custom headers (lower priority than signing headers)
        if custom_headers:
            # Custom headers go first, our signing headers override
            merged = {**custom_headers, **headers}
            headers = merged

        # Deliver via shared HTTP client
        client = get_client()
        url_str = str(url)

        mcp_logger.info(f"[WebhookCallback] Delivering job_id={job_id} status={status} to {url_str}")

        try:
            response = await client.request(
                method=method,
                url=url_str,
                data=payload_bytes,
                headers=headers,
                timeout=timeout,
                retries=max_retries,
                backoff=2.0,  # 2s base backoff for webhook delivery
            )

            mcp_logger.info(
                f"[WebhookCallback] Delivered job_id={job_id} to {url_str} "
                f"| status_code={response.status_code}"
            )
            return True

        except Exception as e:
            # DEAD-LETTER LOG: This is the last line of defense.
            # If we can't deliver after all retries, log everything needed
            # to manually replay or debug the delivery.
            mcp_logger.error(
                f"[WebhookCallback] DEAD-LETTER | job_id={job_id} | url={url_str} | "
                f"status={status} | error={str(e)} | "
                f"payload_size={len(payload_bytes)} bytes"
            )
            raise WebhookDeliveryError(
                f"Webhook delivery to {url_str} failed after {max_retries} retries: {str(e)}"
            ) from e
