from tools.definitions.crm import LeadWriterTool
from tools.definitions.crm_read import LeadReaderTool
from tools.definitions.whatsapp_sender import WhatsAppSenderTool
from tools.definitions.google_sheet_reader import GoogleSheetReaderTool
from tools.definitions.google_sheet_writer import GoogleSheetWriterTool

# Add new tools here (e.g., GoogleSearchTool, CalendarTool)
AVAILABLE_TOOLS = {
    "update_lead": [LeadWriterTool()],
    "read_leads": [LeadReaderTool()],
    "whatsapp_sender": [WhatsAppSenderTool()],
    "googlesheets_reader": [GoogleSheetReaderTool()],
    "googlesheets_writer": [GoogleSheetWriterTool()],
}

def get_tools(tool_names: list[str]):
    """Returns a flattened list of instantiated tools based on requested names"""
    active_tools = []
    for name in tool_names:
        if name in AVAILABLE_TOOLS:
            active_tools.extend(AVAILABLE_TOOLS[name])
    return active_tools