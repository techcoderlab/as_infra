import logging
import sys
from logging.handlers import RotatingFileHandler
from pathlib import Path
from .config import settings

# Define a standard format for all logs
# Added [%(name)s] so you can tell in the console which service is talking
LOG_FORMAT = logging.Formatter("%(asctime)s | %(levelname)s | [%(name)s] | %(message)s")

def setup_logger(logger_name: str, log_file: str | None = None):
    """
    Factory to create independent loggers with rotation and console output.
    """
    # 1. Get the logger
    logger_obj = logging.getLogger(logger_name)
    logger_obj.setLevel(getattr(logging, settings.LOG_LEVEL.upper(), logging.INFO))
    
    # 2. Prevent duplicate logs if this module is imported multiple times
    if logger_obj.hasHandlers():
        return logger_obj

    # 3. Stream Handler (Console/Docker) - CRITICAL for `docker logs`
    sh = logging.StreamHandler(sys.stdout)
    sh.setFormatter(LOG_FORMAT)
    logger_obj.addHandler(sh)

    # 4. File Handler (Optional - Specific to the service)
    if log_file:
        file_path = Path(log_file)
        # Ensure directory exists
        file_path.parent.mkdir(parents=True, exist_ok=True)

        fh = RotatingFileHandler(
            file_path, 
            maxBytes=5_000_000,  # 5MB (Increased for AI verbose logs)
            backupCount=5        # Keep last 5 files
        )
        fh.setFormatter(LOG_FORMAT)
        logger_obj.addHandler(fh)

    return logger_obj

# --- INSTANTIATE LOGGERS ---

# 1. General System Logger (No specific file, just console/Docker)
# Use this for Startup/Shutdown/Global Exceptions
logger = setup_logger("system", settings.SYSTEM_LOG_FILE)

# 2. WhatsApp Logger
# Writes to: settings.WA_SEND_LOG_FILE
wa_logger = setup_logger("whatsapp", settings.WA_SEND_LOG_FILE)

# 3. AI Agent / MCP Logger
# Writes to: settings.AGENT_LOG_FILE (Add this to your config!)
# Fallback: "logs/agent.log" if not set in settings
mcp_sidecar_log_path = getattr(settings, "AGENT_LOG_FILE", "logs/mcp-sidecar_service.log")
mcp_logger = setup_logger("agent", mcp_sidecar_log_path)