from pydantic import BaseModel, Field
from typing import Optional, Dict, Any
from tools.base import BaseTool
from core.config import settings
from core.http import get_client
from core.logger import mcp_logger
from core.decorators import tool_timeout

class LeadData(BaseModel):
    # description logic helps Gemini decide when to omit fields
    temperature: Optional[str] = Field(
        None, 
        description="Omit if not changing. Options: cold, warm, hot."
    )
    status: Optional[str] = Field(
        None, 
        description="Omit if not changing. Use 'closed_won', 'new', etc."
    )
    score: Optional[int] = Field(
        None,
        description="Omit if not changing. Use an integer value."
    )
    won: Optional[bool] = Field(
        None,
        description="Omit if not changing. Use 'true' or 'false'."
    )
    payload: Optional[Dict[str, Any]] = Field(
        None,
        description="Extra JSON data to save in the lead's payload column. Merged with existing data."
    )
    
class UpdateLeadArgs(BaseModel):
    target_id: int = Field(..., description="The numeric ID of the lead.")
    data: LeadData = Field(..., description="The structured update data.")

class LeadWriterTool(BaseTool):
    name = "update_lead"
    description = "Updates lead details. Requires explicit user confirmation first."
    args_schema = UpdateLeadArgs

    @tool_timeout(seconds=10) # Protect this specific tool
    async def run(self, target_id: int, data: dict, context: dict = None):
        context = context or {}
        
        # # 0. Access this tool's config (if any) from context securely
        # config = context.get('tool_configs', {}).get(self.name, {})
        # api_key = config.get('api_key')
        
        
        # 1. Security Check
        tenant_id = context.get("tenant_id")
        if not tenant_id:
            return {"isError": True, "content": {"type": "text", "text": "Tenant context missing."}}

        # 2. DATA CLEANING (The Robust Layer)
        # We convert the 'data' Pydantic dict into a clean dict, 
        # removing anything that is None or empty.
        clean_data = {k: v for k, v in data.items() if v is not None and v != ""}

        if not clean_data:
            return {
                "content": {
                    "type": "text", 
                    "text": "No valid changes were provided. Nothing updated."
                }
            }

        # 3. Preparation
        clean_id = int(float(target_id)) 
        url = f"{settings.AS_API_BASE}/api/internal/leads/{clean_id}/update"
        client = get_client()
        
        try:
            mcp_logger.info(f"[LeadWriter] Tenant:{tenant_id} Updating Lead:{clean_id} with {list(clean_data.keys())}\n\n{url}")
            
            # 4. API Request
            response = await client.post(
                url, 
                json=clean_data, # Only sends requested fields
                headers={
                    "x-service-token": settings.MCP_SIDECAR_CRM_SERVICE_TOKEN,
                    "x-tenant-id": str(tenant_id),
                    "ngrok-skip-browser-warning": "true",
                    "Content-Type": "application/json"
                },
                timeout=15.0
            )
            
            mcp_logger.info(f"[LeadWriter] Tenant:{tenant_id} Updated Lead:{clean_id} | AS API Response: {response}")
        
            return {
                "content": {
                    "type": "text",
                    "text": f"SUCCESS: Lead #{clean_id} updated with {list(clean_data.keys())}."
                }
            }
        
        except Exception as e:
            mcp_logger.error(f"[LeadWriterTool Error] {str(e)}")
            return {
                "isError": True,
                "content": {
                    "type": "text", 
                    "text": "FAILURE: The tool could not complete the update."
                }
            }