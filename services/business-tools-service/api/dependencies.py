import hmac
import hashlib
import time
import redis.asyncio as aioredis
from fastapi import Request, HTTPException
from core.config import settings
from cryptography.hazmat.primitives.asymmetric import ed25519
from cryptography.exceptions import InvalidSignature


# async def verify_hmac_signature(request: Request):
#     """
#     Validates that the request was signed by the Laravel API.
#     Prevents Replay Attacks and Unauthorized Access.
#     """
#     signature = request.headers.get("X-Signature")
#     timestamp = request.headers.get("X-Timestamp")
    
#     # Ensure we use the byte-encoded secret for HMAC
#     secret = settings.MCP_SIDECAR_CRM_SERVICE_TOKEN.encode() 

#     # 1. Check for missing headers
#     if not signature or not timestamp:
#         raise HTTPException(status_code=401, detail="Missing security headers")

#     # 2. Prevent Replay Attack: Reject if request is > 60 seconds old
#     # This protects you if someone intercepts a request log.
#     try:
#         request_time = float(timestamp)
#     except ValueError:
#         raise HTTPException(status_code=401, detail="Invalid timestamp format")

#     if abs(time.time() - request_time) > 60:
#         raise HTTPException(status_code=401, detail="Request expired (clock drift or replay)")

#     # 3. Verify Signature
#     # We must read the body to verify the hash. 
#     # FastAPI caches the body so we can read it again in the endpoint.
#     body = await request.body()
    
#     # Construct the expected string: Timestamp + JSON Body
#     expected_string = timestamp.encode() + body
    
#     # Create the hash
#     expected_signature = hmac.new(secret, expected_string, hashlib.sha256).hexdigest()

#     # Constant-time comparison to prevent timing attacks
#     if not hmac.compare_digest(expected_signature, signature):
#         raise HTTPException(status_code=401, detail="Invalid signature")


# async def verify_asymmetric_signature(request: Request):
#     """
#     Validates that the request was signed by the Laravel API using Ed25519.
#     Prevents Replay Attacks and Unauthorized Access without exposing a shared secret.
#     """
#     signature_hex = request.headers.get("X-Signature")
#     timestamp = request.headers.get("X-Timestamp")

#     # This key can only verify, never sign.
#     try:
#         # 1. Get the raw string and strip spaces, newlines, and accidental quotes
#         raw_hex = settings.LARAVEL_PUBLIC_KEY_HEX.strip().strip('"').strip("'")
        
#         # 2. Validate the hex length (Must be exactly 64 characters for a 32-byte key)
#         if len(raw_hex) != 64:
#             raise ValueError(f"Public key hex must be exactly 64 characters, but got {len(raw_hex)}. Please check your .env file.")
            
#         # 3. Convert to bytes and initialize
#         PUBLIC_KEY_BYTES = bytes.fromhex(raw_hex)
#         public_key = ed25519.Ed25519PublicKey.from_public_bytes(PUBLIC_KEY_BYTES)
#     except Exception as e:
#         # Fail fast on boot if the key is invalid
#         raise RuntimeError(f"Failed to initialize Ed25519 Public Key: {str(e)}")
    
#     # 1. Check for missing headers
#     if not signature_hex or not timestamp:
#         raise HTTPException(status_code=401, detail="Missing security headers")

#     # 2. Prevent Replay Attack: Reject if request is > 60 seconds old
#     try:
#         request_time = float(timestamp)
#     except ValueError:
#         raise HTTPException(status_code=401, detail="Invalid timestamp format")

#     if abs(time.time() - request_time) > 60:
#         raise HTTPException(status_code=401, detail="Request expired (clock drift or replay)")

#     # 3. Verify Asymmetric Signature
#     body = await request.body()
    
#     # Construct the exact signed payload string (Timestamp + JSON Body)
#     expected_payload = timestamp.encode('utf-8') + body
    
#     try:
#         # Convert hex signature header to binary bytes
#         signature_bytes = bytes.fromhex(signature_hex)
        
#         # Verify using the Ed25519 public key.
#         # This will raise an InvalidSignature exception if it has been tampered with.
#         public_key.verify(signature_bytes, expected_payload)
        
#     except (InvalidSignature, ValueError):
#         raise HTTPException(status_code=401, detail="Invalid signature")
#     except Exception:
#         raise HTTPException(status_code=401, detail="Signature verification failed")



# Initialize a global Redis connection pool
redis_client = aioredis.from_url(
    f"redis://{settings.REDIS_HOST}:{settings.REDIS_PORT}", 
    decode_responses=True # Returns strings instead of raw bytes
)

async def verify_hmac_signature(request: Request):
    """
    Multi-Tenant HMAC Verification.
    Validates external developer requests using their unique App ID and Secret.
    """
    app_id = request.headers.get("X-App-Id")
    signature = request.headers.get("X-Signature")
    timestamp = request.headers.get("X-Timestamp")
    
    # 1. Require all three headers now
    if not app_id or not signature or not timestamp:
        raise HTTPException(status_code=401, detail="Missing authentication headers (X-App-Id, X-Signature, X-Timestamp)")

    # 2. Prevent Replay Attacks (60-second window)
    try:
        request_time = float(timestamp)
    except ValueError:
        raise HTTPException(status_code=401, detail="Invalid timestamp format")

    if abs(time.time() - request_time) > 60:
        raise HTTPException(status_code=401, detail="Request expired (clock drift or replay)")

    # 3. Dynamically fetch the Secret from Redis using the App ID
    # This takes <1ms and avoids a heavy PostgreSQL database query
    try:
        developer_secret = await redis_client.get(f"agency-saas-api-database-apikey:{app_id}")
    except Exception as e:
        import logging
        logging.error(f"[verify_hmac_signature] Redis fetch failed: {e}")
        raise HTTPException(status_code=503, detail="Service temporarily unavailable (auth layer)")
    
    if not developer_secret:
        raise HTTPException(status_code=401, detail="Invalid X-App-Id or key has been revoked")

    # 4. Verify HMAC Signature
    body = await request.body()
    expected_string = timestamp.encode('utf-8') + body
    
    # Run the HMAC math using the specific developer's secret
    expected_signature = hmac.new(
        developer_secret.encode('utf-8'), 
        expected_string, 
        hashlib.sha256
    ).hexdigest()

    # Constant-time comparison
    if not hmac.compare_digest(expected_signature, signature):
        raise HTTPException(status_code=401, detail="Invalid signature")

    # Optional: Attach the app_id to the request state so your routes 
    # know exactly which developer is using the AI agent!
    request.state.app_id = app_id