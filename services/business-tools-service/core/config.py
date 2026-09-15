from email.policy import default
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


    REDIS_HOST: str = Field(default="redis", validation_alias="REDIS_HOST") # or "localhost"
    REDIS_PORT: int = Field(default=6379, validation_alias="REDIS_PORT")
    REDIS_PASSWORD: str = Field(default="", validation_alias="REDIS_PASSWORD")
    
    # Maps env var "MCP_SIDECAR_LOG_FILE" -> Class var "AGENT_LOG_FILE"
    AGENT_LOG_FILE: str = Field(
        default="logs/mcp-sidecar_service.log", 
        validation_alias="MCP_SIDECAR_LOG_FILE"
    )
    
    # Data for Sidecar to authenticate with Laravel
    LARAVEL_APP_ID: str = Field(
        default="", 
        validation_alias="LARAVEL_APP_ID"
    )
    LARAVEL_APP_SECRET: str = Field(
        default="", 
        validation_alias="LARAVEL_APP_SECRET"
    )

    MCP_SIDECARD_CLIENT_APP_ID: str = Field(
        default="", 
        validation_alias="MCP_SIDECARD_CLIENT_APP_ID"
    )
    MCP_SIDECARD_CLIENT_SECRET: str = Field(
        default="", 
        validation_alias="MCP_SIDECARD_CLIENT_SECRET"
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
    
    # --- 6. Direct Postgres Access (for vector memory) ---
    DB_HOST: str = Field(default="postgres", validation_alias="DB_HOST")
    DB_PORT: int = Field(default=5432, validation_alias="DB_PORT")
    DB_DATABASE: str = Field(default="as_infra", validation_alias="DB_DATABASE")
    DB_USERNAME: str = Field(default="as_infra", validation_alias="DB_USERNAME")
    # DATA: SECRET — never log, never expose in API responses
    DB_PASSWORD: str = Field(default="", validation_alias="DB_PASSWORD")
    
    # --- 7. Semantic Memory ---
    EMBEDDING_MODEL: str = "BAAI/bge-small-en-v1.5"
    # Cosine distance threshold: 0.0 = identical, 2.0 = opposite.
    # Results with distance >= this value are considered irrelevant.
    MEMORY_RELEVANCE_THRESHOLD: float = Field(default=0.75, validation_alias="MEMORY_RELEVANCE_THRESHOLD")
    
    # # --- 8. Memory Graph Feature Flag ---
    # USE_MEMORY_GRAPH: bool = Field(default=False, validation_alias="USE_MEMORY_GRAPH")

    CLOUDFLARE_API_KEY:str = Field(default="API_KEY", validation_alias="CLOUDFLARE_API_KEY")

    
    
    class Config:
        env_file = ".env"
        # Ignore extra env vars to prevent errors
        extra = "ignore" 

settings = Settings()