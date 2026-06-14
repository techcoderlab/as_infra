# gemini_strategy

import asyncio
from typing import AsyncGenerator, Any, List, Optional
import hashlib

from core.logger import mcp_logger, logger
from core.config import settings
from services.llm import chat_with_gemini
from services.strategies.base import LLMStrategy

# New SDK
from google import genai
from google.genai import types
import datetime

class GeminiStrategy(LLMStrategy):
    def __init__(self, max_iterations=30):
        self.max_iterations = max_iterations

    async def execute(self, api_key: str, model: str, system_prompt: str, effective_history: list, full_user_message: str, tools: list, context: dict, output_format: str = 'text', thinking_budget: Optional[int] = None, use_stream: bool = True, max_iterations: int = 7) -> AsyncGenerator[dict[str, Any], None]:
        chat = await chat_with_gemini(
            api_key,
            model,
            system_prompt,
            effective_history,
            tools,
            json_mode=(output_format == 'json'),
            thinking_budget=thinking_budget
        )

        current_input = full_user_message
        iteration = 0
        api_retry_count = 0
        MAX_API_RETRIES = 5  # Set a limit for consecutive network/API failures
        
        while iteration < max_iterations:
            try:
                function_calls = []
                
                # --- API CALL BLOCK ---
                if use_stream:
                    response_stream = await chat.send_message_stream(message=current_input)
                    async for chunk in response_stream:
                        try:
                            if chunk.text:
                                yield {"type": "token", "data": chunk.text}
                        except ValueError:
                            pass
                        
                        if chunk.candidates and chunk.candidates[0].content and chunk.candidates[0].content.parts:
                            for part in chunk.candidates[0].content.parts:
                                if fn := part.function_call:
                                    args = fn.args
                                    if hasattr(args, "to_dict"):
                                        args = args.to_dict()
                                    function_calls.append({"name": fn.name, "args": args})
                else:
                    response = await chat.send_message(message=current_input)
                    try:
                        if response.text:
                            yield {"type": "token", "data": response.text}
                    except ValueError:
                        pass
                        
                    if response.candidates and response.candidates[0].content and response.candidates[0].content.parts:
                        for part in response.candidates[0].content.parts:
                            if fn := part.function_call:
                                args = fn.args
                                if hasattr(args, "to_dict"):
                                    args = args.to_dict()
                                function_calls.append({"name": fn.name, "args": args})

                # If the API call succeeds, reset the retry counter and increment tool iterations
                api_retry_count = 0
                iteration += 1

                # If no tools were called, we are finished.
                if not function_calls:
                    yield {"type": "done"}
                    break

                # --- TOOL EXECUTION BLOCK ---
                tool_responses = []
                for call in function_calls:
                    tool_name, tool_args = call['name'], call['args']
                    yield {"type": "tool_start", "data": {"tool": tool_name, "args": tool_args}}

                    tool_instance = next((t for t in tools if t.name == tool_name), None)
                    try:
                        if tool_instance:
                            result = await tool_instance.run(**tool_args, context=context)
                        else:
                            result = f"Error: The tool '{tool_name}' is currently disabled or unavailable."
                    except Exception as e:
                        # Auto-Correction mechanism: The error string is sent back to the LLM
                        result = f"Command Failed: {str(e)}"
                    
                    yield {"type": "tool_end", "data": {"tool": tool_name, "result": result}}
                    
                    tool_responses.append(
                        types.Part.from_function_response(
                            name=tool_name,
                            response={"content": str(result)}
                        )
                    )

                # Set input for the next iteration to be the tool responses
                current_input = tool_responses

            except Exception as e:
                # --- INTELLIGENT ERROR HANDLING BLOCK ---
                error_str = str(e).lower()
                mcp_logger.error(f"Gemini API Exception: {error_str}\n")
                
                # Categorize the error
                is_429 = "429" in error_str or "too many requests" in error_str or "quota" in error_str
                is_transient = is_429 or "500" in error_str or "503" in error_str or "timeout" in error_str
                
                if is_transient and api_retry_count < MAX_API_RETRIES:
                    api_retry_count += 1
                    sleep_duration = 0
                    
                    # 1. Attempt to dynamically read 'Retry-After' from the exception object
                    if hasattr(e, 'response') and hasattr(e.response, 'headers'):
                        retry_after = e.response.headers.get('Retry-After')
                        if retry_after:
                            try:
                                sleep_duration = int(retry_after) + 5
                            except ValueError:
                                pass # Keep it 0 to trigger exponential fallback

                    # 2. Fallback to intelligent exponential backoff
                    if sleep_duration == 0:
                        if is_429:
                            # Start at 30s, then 45s, 67s... for rate limits
                            sleep_duration = 30 * (1.5 ** (api_retry_count - 1))
                        else:
                            # Start at 2s, 4s, 8s... for standard server/network hiccups
                            sleep_duration = 2 ** api_retry_count

                    mcp_logger.warning(f"Transient API Error. Attempt {api_retry_count}/{MAX_API_RETRIES}. Sleeping for {sleep_duration:.1f}s...")
                    await asyncio.sleep(sleep_duration)
                    
                    # 'continue' retries the loop without incrementing the tool 'iteration' counter
                    continue 

                # If it's a fatal error (400 Bad Request, Auth, etc) or max retries exhausted, yield the error and abort
                yield {"type": "error", "data": f"Gemini API Error after {api_retry_count} retries: {str(e)}"}
                return

        if iteration >= max_iterations:
            yield {"type": "error", "data": f"Max iterations reached ({max_iterations})."}