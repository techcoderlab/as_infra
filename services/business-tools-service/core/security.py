import time
import hmac
import hashlib
from typing import Tuple

def generate_laravel_hmac_headers(payload_bytes: bytes, app_id: str, secret: str) -> dict:
    """
    Generates HMAC-SHA256 headers for requests to Laravel.
    Returns a dictionary of headers.
    """
    timestamp = str(time.time())
    signature = hmac.new(
        secret.encode('utf-8'),
        timestamp.encode('utf-8') + payload_bytes,
        hashlib.sha256
    ).hexdigest()
    
    return {
        "X-App-Id": app_id,
        "X-Timestamp": timestamp,
        "X-Signature": signature
    }
