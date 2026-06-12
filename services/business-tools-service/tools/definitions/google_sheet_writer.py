from pydantic import BaseModel, Field
from typing import Optional, List, Literal
from tools.base import BaseTool
from integrations.googlesheets import GoogleSheetsClient
from core.decorators import tool_timeout
from core.logger import mcp_logger


class SheetWriteArgs(BaseModel):
    spreadsheet_id: str = Field(..., description="The ID of the Google Spreadsheet.")
    range_name: str = Field(..., description="The A1 notation of the range or sheet to write to (e.g. 'Sheet1!A1').")
    values: List[List[str]] = Field(..., description="The 2D list of values to write. All values must be strings.")
    mode: Literal["OVERWRITE", "APPEND"] = Field("APPEND", description="Mode: 'OVERWRITE' replaces data in the range. 'APPEND' adds rows to the end of the sheet.")

class GoogleSheetWriterTool(BaseTool):
    name = "googlesheets_writer"
    description = (
        "Writes or Appends data to a Google Sheet. "
        "Supports 'OVERWRITE' (updates specific cells) or 'APPEND' (adds new rows). "
        "Requires valid 2D array of strings."
    )
    args_schema = SheetWriteArgs

    @tool_timeout(seconds=30)
    async def run(
        self, 
        spreadsheet_id: str, 
        range_name: str, 
        values: List[List[str]],
        mode: str = "APPEND",
        context: dict = None
    ):
        context = context or {}
        # 1. Config Retrieval
        tool_configs = context.get('tool_configs', {})

        # Debugging: Log available keys
        mcp_logger.info(f"[Google Sheet Writer Tool] Available Config Keys: {list(tool_configs.keys())}")

        # Check 'googlesheets_writer' (self.name) OR 'googlesheets' (provider name)
        gs_config = tool_configs.get(self.name) or tool_configs.get('googlesheets')

        if not gs_config:
            return {
                "isError": True,
                "content": {
                    "type": "text", 
                    "text": "Configuration Error: No 'googlesheets' configuration found."
                }
            }

        try:
            # 2. Client Init
            client = GoogleSheetsClient(service_account_info=gs_config)

            # 3. Execution
            if mode == "OVERWRITE":
                result_msg = client.write_cells(spreadsheet_id, range_name, values)
            else:
                result_msg = client.append_cells(spreadsheet_id, range_name, values)

            return {
                "content": {
                    "type": "text",
                    "text": f"SUCCESS: {result_msg}"
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
