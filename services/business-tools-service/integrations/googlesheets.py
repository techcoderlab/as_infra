from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError
from core.logger import mcp_logger
import json

SCOPES = ['https://www.googleapis.com/auth/spreadsheets']

class GoogleSheetsClient:
    def __init__(self, service_account_info: dict):
        """
        Initialize Google Sheets Client with Service Account Info.
        :param service_account_info: Dictionary containing the Service Account JSON structure.
        """
        
        # Before passing to from_service_account_info
        if "private_key" in service_account_info:
            service_account_info["private_key"] = service_account_info["private_key"].replace("\\n", "\n").strip("'\" ")
        
        
        mcp_logger.info(f"DEBUG KEY START: {repr(service_account_info['private_key'][:50])}")
        try:
            self.creds = service_account.Credentials.from_service_account_info(
                service_account_info, scopes=SCOPES
            )
            self.service = build('sheets', 'v4', credentials=self.creds, cache_discovery=False)
            
        except Exception as e:
            mcp_logger.error(f"[GoogleSheetsClient] Init Error: {str(e)}")
            raise ValueError(f"Failed to initialize Google Sheets Client: {str(e)}")

    def _handle_api_error(self, e: HttpError, spreadsheet_id: str):
        """Maps Google API errors to readable messages."""
        reason = e.reason
        if e.resp.status == 403:
            return f"Permission Denied: The service account does not have access to this sheet ({spreadsheet_id}). Please share the sheet with the service account email."
        elif e.resp.status == 404:
            return f"Not Found: Spreadsheet ID '{spreadsheet_id}' could not be found."
        else:
            return f"Google Sheets API Error ({e.resp.status}): {reason}"

    def list_sheets(self, spreadsheet_id: str) -> list[str]:
        """Returns a list of sheet titles in the spreadsheet."""
        try:
            sheet_metadata = self.service.spreadsheets().get(spreadsheetId=spreadsheet_id).execute()
            sheets = sheet_metadata.get('sheets', [])
            return [sheet.get("properties", {}).get("title", "Unknown") for sheet in sheets]
        except HttpError as e:
            raise Exception(self._handle_api_error(e, spreadsheet_id))
        except Exception as e:
            raise Exception(f"Unexpected error listing sheets: {str(e)}")

    def read_cells(self, spreadsheet_id: str, range_name: str) -> list[list[str]]:
        """Reads values from a specific range."""
        try:
            result = self.service.spreadsheets().values().get(
                spreadsheetId=spreadsheet_id, range=range_name
            ).execute()
            rows = result.get('values', [])
            return rows
        except HttpError as e:
            raise Exception(self._handle_api_error(e, spreadsheet_id))
        except Exception as e:
            raise Exception(f"Unexpected error reading cells: {str(e)}")

    def write_cells(self, spreadsheet_id: str, range_name: str, values: list[list[str]]):
        """Writes (overwrites) values to a specific range."""
        try:
            body = {
                'values': values
            }
            result = self.service.spreadsheets().values().update(
                spreadsheetId=spreadsheet_id, range=range_name,
                valueInputOption="USER_ENTERED", body=body
            ).execute()
            return f"Updated {result.get('updatedCells')} cells."
        except HttpError as e:
            raise Exception(self._handle_api_error(e, spreadsheet_id))
        except Exception as e:
            raise Exception(f"Unexpected error writing cells: {str(e)}")

    def append_cells(self, spreadsheet_id: str, range_name: str, values: list[list[str]]):
        """Appends values to a sheet/range."""
        try:
            body = {
                'values': values
            }
            result = self.service.spreadsheets().values().append(
                spreadsheetId=spreadsheet_id, range=range_name,
                valueInputOption="USER_ENTERED", body=body
            ).execute()
            return f"Appended {result.get('updates', {}).get('updatedCells')} cells."
        except HttpError as e:
            raise Exception(self._handle_api_error(e, spreadsheet_id))
        except Exception as e:
            raise Exception(f"Unexpected error appending cells: {str(e)}")
