import httpx
import asyncio
from core.logger import logger

# Initialize a standard logger for this module

class HttpClient:
    """
    Shared HTTP Client with per-request retry, backoff, and smart error handling.

    IMPORTANT: This is used as a singleton. All config (timeout, retries, backoff)
    is passed per-request via kwargs to avoid race conditions between concurrent callers.

    Parameters:
        default_timeout: Default timeout for requests (overridable per-call).
        default_retries: Default retry count (overridable per-call).
        default_backoff: Default backoff base in seconds (overridable per-call).
    """
    def __init__(self, default_timeout: float = 15.0, default_retries: int = 3, default_backoff: float = 0.5):
        self._default_timeout = default_timeout
        self._default_retries = default_retries
        self._default_backoff = default_backoff
        # Connection pool created once, reused across all requests
        self._client = httpx.AsyncClient(
            timeout=self._default_timeout,
            limits=httpx.Limits(max_keepalive_connections=20, max_connections=100)
        )

    async def request(self, method: str, url: str, **kwargs) -> httpx.Response:
        """
        Execute an HTTP request with per-call retry and backoff config.

        Parameters:
            method: HTTP method (GET, POST, PUT, etc.)
            url: Target URL.
            timeout: Per-request timeout override (default: instance default).
            retries: Per-request retry count override (default: instance default).
            backoff: Per-request backoff base override (default: instance default).
            **kwargs: Passed through to httpx (headers, json, data, etc.)

        Returns:
            httpx.Response on success.

        Raises:
            httpx.HTTPStatusError: On non-retryable 4xx errors.
            Exception: After all retries exhausted.
        """
        # Extract per-request overrides without mutating singleton state
        request_timeout = kwargs.pop("timeout", self._default_timeout)
        request_retries = kwargs.pop("retries", self._default_retries)
        request_backoff = kwargs.pop("backoff", self._default_backoff)
        
        last_exc = None

        for attempt in range(1, request_retries + 1):
            try:
                resp = await self._client.request(method, url, timeout=request_timeout, **kwargs)
                resp.raise_for_status()
                return resp

            except httpx.HTTPStatusError as e:
                # 4xx (except 429 rate limit) — non-retryable, raise immediately
                if e.response.status_code < 500 and e.response.status_code != 429:
                    logger.error(f"HTTP Client Error {e.response.status_code}: {e.response.text}")
                    raise e
                
                # 5xx or 429 — retryable
                last_exc = e
                delay = request_backoff * attempt
                logger.warning(f"HTTP {e.response.status_code} on attempt {attempt}/{request_retries}, retrying in {delay}s: {e}")
                await asyncio.sleep(delay)
                
            except Exception as e:
                # Network errors, timeouts, DNS failures
                last_exc = e
                delay = request_backoff * attempt
                logger.warning(f"HTTP attempt {attempt}/{request_retries} failed, retrying in {delay}s: {e}")
                await asyncio.sleep(delay)
        
        if last_exc:
            raise last_exc
        raise Exception(f"Request to {url} failed after {request_retries} retries")

    async def get(self, url: str, **kwargs) -> httpx.Response:
        """HTTP GET with per-request config via kwargs (timeout, retries, backoff)."""
        return await self.request("GET", url, **kwargs)

    async def post(self, url: str, **kwargs) -> httpx.Response:
        """HTTP POST with per-request config via kwargs (timeout, retries, backoff)."""
        return await self.request("POST", url, **kwargs)
    
    async def put(self, url: str, **kwargs) -> httpx.Response:
        """HTTP PUT with per-request config via kwargs (timeout, retries, backoff)."""
        return await self.request("PUT", url, **kwargs)

    async def close(self) -> None:
        await self._client.aclose()


# --- SINGLETON MANAGEMENT ---
# This ensures we reuse the SAME HttpClient instance across the whole app.

_client_instance: HttpClient | None = None

def get_client() -> HttpClient:
    """Returns the singleton instance of HttpClient."""
    global _client_instance
    if _client_instance is None:
        _client_instance = HttpClient()
    return _client_instance

async def close_client():
    """Closes the singleton instance connection pool."""
    global _client_instance
    if _client_instance:
        await _client_instance.close()
        _client_instance = None