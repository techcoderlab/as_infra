from pydantic import BaseModel, Field
from typing import Optional
from tools.base import BaseTool
from core.config import settings
from core.http import get_client
from core.logger import mcp_logger
from core.decorators import tool_timeout
import json
from core.security import generate_laravel_hmac_headers

# 1. Define the Arguments Schema
class LeadReadArgs(BaseModel):
    query: Optional[str] = Field(
        None, 
        description="The search term (Name, Email, Company, Phone/Mobile Number, ID, etc). Optional if filtering by date only."
    )
    date_filter: Optional[str] = Field(
        None, 
        description="Timeframe filter. Options: 'today', 'this_week', 'this_month', 'ytd' (Year to Date), 'custom'."
    )
    start_date: Optional[str] = Field(
        None, 
        description="Start date (YYYY-MM-DD). Required only if date_filter is 'custom'."
    )
    end_date: Optional[str] = Field(
        None, 
        description="End date (YYYY-MM-DD). Required only if date_filter is 'custom'."
    )
    limit: int = Field(5, description="Maximum number of results to return.")

# 2. Define the Tool Logic
class LeadReaderTool(BaseTool):
    name = "read_leads"
    description = (
        "Queries the Knowledge Hub to find leads. Supports text search AND date filtering. "
        "Use this for questions like 'Find John' or 'How many leads did we get this week?'"
    )
    args_schema = LeadReadArgs

    @tool_timeout(seconds=10) # Protect this specific tool
    async def run(
        self, 
        query: str = None, 
        date_filter: str = None, 
        start_date: str = None, 
        end_date: str = None, 
        limit: int = 5, 
        context: dict = None
    ):
        context = context or {}
        
        # 1. Security & Context Validation
        tenant_id = context.get("tenant_id", None)
        
        if not tenant_id:
            mcp_logger.error(f"[LeadReaderTool] Missing Tenant Context. Aborting.")
            return {
                "isError": True, 
                "content": {
                    "type": "text", 
                    "text": "Error: Missing Tenant Context. I cannot search without knowing which account to access."
                }
            }
        
        # 2. Prepare Payload
        payload = {
            "query": query, 
            "limit": limit,
            "date_filter": date_filter,
            "start_date": start_date,
            "end_date": end_date
        }

        # 3. Execution
        url = f"{settings.AS_API_BASE}/api/internal/leads/search"
        client = get_client()
        
        mcp_logger.info(f"[LeadReaderTool] Tenant:{tenant_id} Filter:{date_filter} Query:{query}")
        
        payload_bytes = json.dumps(payload).encode('utf-8')
        hmac_headers = generate_laravel_hmac_headers(payload_bytes, settings.LARAVEL_APP_ID, settings.LARAVEL_APP_SECRET)
        
        req_headers = {
            "x-tenant-id": str(tenant_id),
            "ngrok-skip-browser-warning": "true",
            "Content-Type": "application/json"
        }
        req_headers.update(hmac_headers)

        try:
            response = await client.post(
                url, 
                content=payload_bytes, 
                headers=req_headers,
                timeout=15.0 # Robustness
            )
            
            data = response.json()
            results = data.get("results", [])
            count = data.get("count", 0)

            # Special case: If user asked for a count/stat but no detailed list is needed
            # The backend returns 'count', so we should mention it.
            if not results and count == 0:
                return {"content": {"type": "text", "text": "No matching leads found."}}

            # Format results for the LLM
            formatted_results = [f"Total Matches Found: {count}"]
            
            for lead in results:
                # Dynamic extraction of all available fields
                details = ", ".join([f"{k}: {v}" for k, v in lead.items() if v])
                formatted_results.append(f"- Lead [{details}]")
            
            return {
                "content": {
                    "type": "text",
                    "text": "\n".join(formatted_results)
                }
            }
        except Exception as e:
            mcp_logger.error(f"[LeadReaderTool Error] {str(e)}")
            
            return {
                "isError": True,
                "content": {
                    "type": "text", 
                    "text": f"Search failed. The database is unreachable or returned an error."
                },
            }