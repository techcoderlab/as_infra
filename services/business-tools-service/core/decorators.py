import asyncio
import functools
from core.logger import mcp_logger

def tool_timeout(seconds: int = 15):
    """
    Decorator to enforce a maximum execution time on an AI tool.
    Returns a string error to the LLM if the timeout is hit.
    """
    def decorator(func):
        @functools.wraps(func)
        async def wrapper(*args, **kwargs):
            try:
                # Use asyncio.wait_for to wrap the execution
                return await asyncio.wait_for(func(*args, **kwargs), timeout=seconds)
            except asyncio.TimeoutError:
                mcp_logger.warning(f"Tool {func.__name__} timed out after {seconds}s")
                return f"Error: The tool '{func.__name__}' took too long to respond. Please try another method or check parameters."
            except Exception as e:
                mcp_logger.error(f"Error in tool {func.__name__}: {str(e)}")
                return f"Error: Tool execution failed with message: {str(e)}"
        return wrapper
    return decorator