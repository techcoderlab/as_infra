import time
from contextlib import asynccontextmanager
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

# --- IMPORTS FROM YOUR MODULES ---
from core.rate_limiter import rate_limiter_registry as registry
from core.http import get_client, close_client
from core.config import settings
from core.logger import logger

# --- ROUTERS ---
from api.routers import agent, whatsapp

# --- START ---
start_time = time.time()


# --- LIFESPAN MANAGER ---
@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup: Initialize the shared connection pool
    logger.info("Starting API & initializing HTTP Singleton...")
    get_client() # This creates the connection pool once
    # generate_keypair()
    
    yield
    # Shutdown: Cleanly close the pool
    logger.info("Shutting down API & closing connections...")
    await close_client()

app = FastAPI(
    title="Business Tools & MCP Sidecar Service", 
    lifespan=lifespan, 
    root_path=settings.ROOT_PATH
)

# def generate_keypair():
#     # pyrefly: ignore [missing-import]
#     from cryptography.hazmat.primitives.asymmetric import ed25519

#     # Generate a brand new secure keypair
#     private_key = ed25519.Ed25519PrivateKey.generate()
#     public_key = private_key.public_key()

#     # Export them as hex strings to drop into your environment files
#     print("LARAVEL_PRIVATE_KEY_HEX =", private_key.private_bytes_raw().hex())
#     print("LARAVEL_PUBLIC_KEY_HEX  =", public_key.public_bytes_raw().hex())

# Limit global request size to 1MB
@app.middleware("http")
async def limit_upload_size(request: Request, call_next):
    content_length = request.headers.get('content-length')
    if content_length and int(content_length) > 20_000_000: # 20 Megabyte
        return JSONResponse(status_code=413, content={"error": "Payload too large"})
    return await call_next(request)

@app.middleware("http")
async def rate_limit_middleware(request: Request, call_next):
    # Only apply to Agent endpoints
    if request.url.path in ["/v1/agent/enqueue", "/v1/agent/chat"]:
        tenant_id = request.headers.get("X-Tenant-ID", "global")
        
        # Get bucket for this tenant. 
        # Using SIDECAR_RATE_LIMIT_PER_MINUTE as the default capacity.
        bucket = registry.get_bucket(f"agent:{tenant_id}", settings.SIDECAR_RATE_LIMIT_PER_MINUTE)
        wait_time = bucket.consume()

        if wait_time > 0:
            retry_after = int(wait_time)
            return JSONResponse(
                status_code=429,
                content={
                    "error": "Too many requests",
                    "message": f"Tenant {tenant_id} rate limit reached. Please try again in {retry_after}s."
                },
                headers={"Retry-After": str(retry_after)}
            )
            
    return await call_next(request)

# --- GLOBAL EXCEPTION HANDLER ---
@app.exception_handler(Exception)
async def global_exception_handler(request: Request, exc: Exception):
    logger.error(f"Global Error: {str(exc)}")
    return JSONResponse(
        status_code=500,
        content={"status": "error", "message": "Internal Server Error", "detail": str(exc)},
    )

# Include Routers
app.include_router(agent.router)
app.include_router(whatsapp.router)

@app.get("/health")
async def health_check():
    import psutil
    import os
    process = psutil.Process(os.getpid())
    return {
        "status": "healthy",
        "service": "mcp-sidecar",
        "version": "1.0.0",
        "uptime": time.time() - start_time,
        "server_stats": {
            "cpu_usage_percent": psutil.cpu_percent(),
            "memory_usage_mb": process.memory_info().rss / 1024 / 1024,
            "free_memory_gb": psutil.virtual_memory().available / (1024**3)
        }
    }