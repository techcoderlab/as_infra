from pydantic_settings import BaseSettings
from pydantic import Field

class Settings(BaseSettings):
    
    # --- 1. Standard Config (Auto-reads env var of same name) ---
    # Pydantic automatically looks for "ENV", "PORT", etc. in your environment.
    ENV: str = "production"
    PORT: int = 8017
    ROOT_PATH: str = "app"
    
    # --- 2. Logging ---
    
    SYSTEM_LOG_FILE: str = "logs/business-tools.log"
    
    LOG_LEVEL: str = "INFO"
    WA_SEND_LOG_FILE: str = "logs/whatsapp_service.log"
    
    # --- 3. Aliased Config (Different Env Name -> Class Name) ---
    
    # Maps env var "MCP_SIDECAR_LOG_FILE" -> Class var "AGENT_LOG_FILE"
    AGENT_LOG_FILE: str = Field(
        default="logs/mcp-sidecar_service.log", 
        validation_alias="MCP_SIDECAR_LOG_FILE"
    )
    
    # Maps env var "SERVICE_TOKEN" -> Class var "MCP_SIDECAR_CRM_SERVICE_TOKEN"
    MCP_SIDECAR_CRM_SERVICE_TOKEN: str = Field(
        default="", 
        validation_alias="SERVICE_TOKEN"
    )

    # Maps env var "APP_URL" -> Class var "AS_API_BASE"
    AS_API_BASE: str = Field(
        default="", 
        validation_alias="APP_URL"
    )


    # DATA: SECRET — never log, never expose in API responses
    GEMINI_API_KEY: str = Field(
        default="", 
        validation_alias="GEMINI_API_KEY"
    )
    
    WA_API_VERSION: str = "v24.0"
    WA_API_BASE: str = "https://graph.facebook.com/v24.0"
    WA_RATE_LIMIT_PER_MINUTE: int = 20

    SIDECAR_RATE_LIMIT_PER_MINUTE: int = 100 # 50 x Total Gunicorn workers
    
    # --- 5. Agent Enqueue Config ---
    MAX_CONCURRENT_AGENT_JOBS: int = 10  # Semaphore bound for parallel LLM executions
    ALLOWED_CALLBACK_DOMAINS: str = ""   # Comma-separated allowlist (empty = allow all)
    
    
    
    """ Configuration settings for document extractor api """   
    DOCUMENT_MAX_CONCURRENT_EXTRACTIONS: int = 5
    DOCUMENT_RATE_LIMIT_DELAY: float = 1.5  # Seconds to sleep between fulfilling semaphore slots
    
    class Config:
        env_file = ".env"
        # Ignore extra env vars to prevent errors
        extra = "ignore" 

settings = Settings()