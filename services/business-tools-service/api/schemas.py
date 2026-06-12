from typing import Optional, Dict, Any, List
from pydantic import BaseModel, Field, HttpUrl, field_validator, model_validator

# 1. Configuration for the specific tenant (Client)
class WhatsAppConfig(BaseModel):
    phone_number_id: str = Field(..., description="The sender's Phone ID")
    access_token: str = Field(..., description="The sender's Meta API Token")

# 2. The Message Content
class TemplateInfo(BaseModel):
    name: str
    language: str = "en_US"

class MessageRequest(BaseModel):
    recipient_phone: str = Field(..., description="Target phone number (e.g., 92300...)")
    type: str = Field(..., pattern="^(text|template)$")
    template: Optional[TemplateInfo] = None
    text_body: Optional[str] = None
    
    # Optional: Custom ID for tracking in your logs
    trace_id: Optional[str] = None

# 3. The Combined Payload (What n8n sends)
class AutomationPayload(BaseModel):
    config: WhatsAppConfig
    message: MessageRequest 
    
    
    

# ─────────────────────────────────────────────────────
# Agent Enqueue — Decoupled Webhook Callback Schemas
# ─────────────────────────────────────────────────────

class CallbackConfig(BaseModel):
    """
    Per-caller webhook configuration. Allows any 3rd-party API
    (not just Laravel) to receive async agent results.
    
    Parameters:
        url: The callback endpoint to POST results to.
        method: HTTP method for callback (POST or PUT).
        signing_secret: HMAC-SHA256 secret for signing the callback payload.
                        If None, falls back to the env MCP_SIDECAR_CRM_SERVICE_TOKEN.
        headers: Additional headers to include in the callback request.
        timeout_seconds: Max wait time for the callback HTTP request.
        max_retries: Number of retry attempts on 5xx / network errors.
    """
    url: HttpUrl
    method: str = Field("POST", pattern="^(POST|PUT)$")
    signing_secret: Optional[str] = None
    headers: Dict[str, str] = Field(default_factory=dict)
    timeout_seconds: float = Field(default=10.0, ge=1.0, le=60.0)
    max_retries: int = Field(default=3, ge=0, le=10)


class AgentEnqueueRequest(BaseModel):
    """
    Validated request schema for the /v1/agent/enqueue endpoint.
    Decoupled from Laravel — works with any caller that provides a callback config.

    Parameters:
        job_id: Unique identifier for this job. Maps from 'job_uuid' for backward compat.
        callback: Webhook configuration for result delivery.
        provider: LLM provider name (openai, gemini, anthropic).
        api_key: LLM provider API key.
        model: LLM model identifier.
        system_prompt: System-level prompt for the LLM.
        user_prompt: User's message / instruction.
        tools: List of tool names to make available.
        tool_configs: Per-tool configuration (e.g., WhatsApp credentials).
        context: Additional context passed through to tools.
        history: Conversation history for multi-turn interactions.
        output_format: Expected output format (json or text).
    """
    job_id: str = Field(..., alias="job_uuid", description="Unique job identifier")
    callback: Optional[CallbackConfig] = None
    # TRADE-OFF: P2 Security — callback.signing_secret + webhook_url allowlist
    # enforced at service layer, not schema layer, to keep backward compat
    webhook_url: Optional[str] = None  # Legacy Laravel field, deprecated
    provider: str = "openai"
    api_key: str = Field(..., alias="apiKey")
    model: str = "gpt-4o"
    system_prompt: str = Field(default="", alias="systemPrompt")
    user_prompt: str = Field(..., alias="userPrompt")
    tools: List[str] = Field(default_factory=list)
    tool_configs: Dict[str, Any] = Field(default_factory=dict)
    context: Dict[str, Any] = Field(default_factory=dict)
    history: List[Dict[str, Any]] = Field(default_factory=list)
    output_format: str = "json"
    thinking_budget: Optional[int] = Field(
        default=0,
        description="Max thinking tokens for 2.5 thinking models. 0=disable, None=model default.",
        ge=0, le=24576
    )

    class Config:
        populate_by_name = True  # Accept both alias and field name

    @model_validator(mode='after')
    def check_callback_or_webhook(self):
        """Ensure at least one callback mechanism is provided."""
        if not self.callback and not self.webhook_url:
            raise ValueError("Either 'callback' config or legacy 'webhook_url' must be provided")
        return self