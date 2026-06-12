import hmac
import hashlib
import time
from fastapi import Request, HTTPException
from core.config import settings

async def verify_hmac_signature(request: Request):
    """
    Validates that the request was signed by the Laravel API.
    Prevents Replay Attacks and Unauthorized Access.
    """
    signature = request.headers.get("X-Signature")
    timestamp = request.headers.get("X-Timestamp")
    
    # Ensure we use the byte-encoded secret for HMAC
    secret = settings.MCP_SIDECAR_CRM_SERVICE_TOKEN.encode() 

    # 1. Check for missing headers
    if not signature or not timestamp:
        raise HTTPException(status_code=401, detail="Missing security headers")

    # 2. Prevent Replay Attack: Reject if request is > 60 seconds old
    # This protects you if someone intercepts a request log.
    try:
        request_time = float(timestamp)
    except ValueError:
        raise HTTPException(status_code=401, detail="Invalid timestamp format")

    if abs(time.time() - request_time) > 60:
        raise HTTPException(status_code=401, detail="Request expired (clock drift or replay)")

    # 3. Verify Signature
    # We must read the body to verify the hash. 
    # FastAPI caches the body so we can read it again in the endpoint.
    body = await request.body()
    
    # Construct the expected string: Timestamp + JSON Body
    expected_string = timestamp.encode() + body
    
    # Create the hash
    expected_signature = hmac.new(secret, expected_string, hashlib.sha256).hexdigest()

    # Constant-time comparison to prevent timing attacks
    if not hmac.compare_digest(expected_signature, signature):
        raise HTTPException(status_code=401, detail="Invalid signature")
