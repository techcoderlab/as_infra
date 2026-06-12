from pydantic import BaseModel, Field
from typing import Optional
from tools.base import BaseTool
from integrations.googlesheets import GoogleSheetsClient
from core.decorators import tool_timeout
from core.logger import mcp_logger

class SheetReadArgs(BaseModel):
    spreadsheet_id: str = Field(..., description="The ID of the Google Spreadsheet.")
    range_name: Optional[str] = Field(None, description="The A1 notation of the range to read (e.g. 'Sheet1!A1:B10'). Required if get_sheet_names is False.")
    get_sheet_names: bool = Field(False, description="If True, returns a list of sheet names instead of cell data.")

class GoogleSheetReaderTool(BaseTool):
    name = "googlesheets_reader"
    description = (
        "Reads data from a Google Sheet. "
        "Can read specific cell ranges or list all sheet names within a spreadsheet. "
        "Use 'get_sheet_names=True' to discover available sheets."
    )
    args_schema = SheetReadArgs

    @tool_timeout(seconds=30)
    async def run(
        self, 
        spreadsheet_id: str, 
        range_name: str = None, 
        get_sheet_names: bool = False, 
        context: dict = None
    ):
        context = context or {}
        # 1. Config Retrieval
        tool_configs = context.get('tool_configs', {})

        # Debugging: Log available keys
        mcp_logger.info(f"[Google Sheet Reader Tool] Available Config Keys: {list(tool_configs.keys())}")

        # Check 'googlesheets_writer' (self.name) OR 'googlesheets' (provider name)
        gs_config = tool_configs.get(self.name) or tool_configs.get('googlesheets')

        if not gs_config:
            return {
                "isError": True,
                "content": {
                    "type": "text", 
                    "text": "Configuration Error: No 'googlesheets' configuration found. Please check Agent Tool Configs."
                }
            }

        try:
            # 2. Client Init
            client = GoogleSheetsClient(service_account_info=gs_config)

            # 3. Execution
            if get_sheet_names:
                sheets = client.list_sheets(spreadsheet_id)
                return {
                    "content": {
                        "type": "text",
                        "text": f"Sheets found in '{spreadsheet_id}':\n" + "\n".join(f"- {s}" for s in sheets)
                    }
                }

            if not range_name:
                 return {
                    "isError": True,
                    "content": {
                        "type": "text", 
                        "text": "Usage Error: 'range_name' is required when get_sheet_names is False."
                    }
                }

            rows = client.read_cells(spreadsheet_id, range_name)
            
            if not rows:
                 return {
                    "content": {
                        "type": "text",
                        "text": f"No data found in range '{range_name}'."
                    }
                }

            # Format simple CSV-like output for LLM readability
            output_text = f"Data from '{range_name}':\n"
            for row in rows:
                output_text += " | ".join(row) + "\n"

            return {
                "content": {
                    "type": "text",
                    "text": output_text
                }
            }

        except Exception as e:
            return {
                "isError": True,
                "content": {
                    "type": "text", 
                    "text": f"Error: {str(e)}"
                }
            }
