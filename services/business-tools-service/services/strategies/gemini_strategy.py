import asyncio
from typing import AsyncGenerator, Any, List, Optional
import hashlib

from core.logger import mcp_logger, logger
from core.config import settings
from services.llm import stream_gemini
from services.strategies.base import LLMStrategy

# New SDK
from google import genai
from google.genai import types
import datetime

# Old SDK (kept ONLY for the caching feature until you migrate it)
# import google.generativeai as old_genai
# from google.generativeai import caching


class GeminiStrategy(LLMStrategy):
    def __init__(self, max_iterations=30):
        self.max_iterations = max_iterations

    async def run_stream(self, api_key: str, model: str, system_prompt: str, effective_history: list, full_user_message: str, tools: list, context: dict, output_format: str = 'text', thinking_budget: Optional[int] = None, use_stream: bool = True, max_iterations: int = 7) -> AsyncGenerator[dict[str, Any], None]:
        chat = await stream_gemini(
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
        
        while iteration < max_iterations:
            iteration += 1
            try:
                function_calls = []
                
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

                if not function_calls:
                    yield {"type": "done"}
                    break

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
                        result = f"Command Failed: {str(e)}"
                    
                    yield {"type": "tool_end", "data": {"tool": tool_name, "result": result}}
                    
                    tool_responses.append(
                        types.Part.from_function_response(
                            name=tool_name,
                            response={"content": str(result)}
                        )
                    )

                current_input = tool_responses

            except Exception as e:
                mcp_logger.error(f"Gemini Loop Error: {e}", exc_info=True)
                if "429" in str(e):
                    if iteration < 2:
                        await asyncio.sleep(5)
                        continue
                return

        if iteration >= max_iterations:
            yield {"type": "error", "data": "Max iterations reached (Gemini Loop)."}

    # async def run(self, prompt: str, use_cache: bool = True, **kwargs) -> str:
    #     if use_cache:
    #         content_hash = hashlib.md5(prompt.encode()).hexdigest()
            
    #         # Using old SDK for Context Caching as it is robust there
    #         old_genai.configure(api_key=settings.GEMINI_API_KEY)
            
    #         try:
    #             # FIX 1: Use datetime.timedelta for ttl
    #             cache = caching.CachedContent.create(
    #                 model='models/gemini-2.5-flash-lite',
    #                 display_name=f"cache_{content_hash}",
    #                 contents=[prompt],
    #                 ttl=datetime.timedelta(seconds=3600) 
    #             )
                
    #             # FIX 2: You MUST use from_cached_content to actually apply the discount
    #             model = old_genai.GenerativeModel.from_cached_content(cached_content=cache)
                
    #             # Since the full prompt is already in the cache, we just trigger it
    #             # Using the async version so we don't block the FastAPI event loop
    #             response = await model.generate_content_async("Analyze the cached content and provide the final answer.")
                
    #             return response.text
                
    #         except Exception as e:
    #             logger.error(f"Context Caching Failed: {str(e)}. Falling back to standard run.")
    #             return await self._standard_run(prompt)
        
    #     return await self._standard_run(prompt)

    # async def _standard_run(self, prompt: str) -> str:
    #     """Standard, non-cached generation using the New SDK."""
    #     client = genai.Client(api_key=settings.GEMINI_API_KEY)
        
    #     response = await client.aio.models.generate_content(
    #         model='gemini-2.5-flash-lite',
    #         contents=prompt
    #     )
    #     return response.text
    
    # async def run_agentic(self, prompt: str, tools: List[Any], max_iterations: int = 5) -> str:
    #     # 1. Initialize the NEW SDK Client
    #     client = genai.Client(api_key=settings.GEMINI_API_KEY)
        
    #     # 2. Fix the AttributeError: Use to_gemini_schema() instead of get_schema()
    #     gemini_tool_declarations = []
    #     if tools:
    #         for t in tools:
    #             if hasattr(t, "to_gemini_schema"):
    #                 decl = t.to_gemini_schema()
    #                 gemini_tool_declarations.append(types.FunctionDeclaration(**decl))
    #             else:
    #                 logger.warning(f"Tool {t.name} is missing 'to_gemini_schema'.")

    #     chat_config = types.GenerateContentConfig(temperature=0.2)
    #     if gemini_tool_declarations:
    #         chat_config.tools = [types.Tool(function_declarations=gemini_tool_declarations)]

    #     # 3. Create Async Chat
    #     chat = client.aio.chats.create(
    #         model="gemini-2.5-flash",
    #         config=chat_config
    #     )
        
    #     current_input = prompt
        
    #     for i in range(max_iterations):
    #         try:
    #             # Send message (text or tool results)
    #             response = await chat.send_message(current_input)
                
    #             # Check if model wants to call a tool (using new SDK syntax)
    #             function_calls = response.function_calls
    #             if not function_calls:
    #                 return response.text

    #             # 4. Handle Tool Calls and format responses for the next iteration
    #             tool_responses = []
    #             for fn in function_calls:
    #                 name = fn.name
    #                 args = fn.args
    #                 if hasattr(args, "to_dict"):
    #                     args = args.to_dict()

    #                 tool_result = await self.execute_tool(name, args)
                    
    #                 # Convert the tool output into the required Part format for the LLM
    #                 # print(f"Tool ({name}) result: ", str(args))
    #                 tool_responses.append(
    #                     types.Part.from_function_response(
    #                         name=name,
    #                         response={"content": str(tool_result)}
    #                     )
    #                 )
                
    #             # Update input for the next loop to be the tool responses
    #             current_input = tool_responses
                
    #         except Exception as e:
    #             logger.error(f"Agent error: {str(e)}")
    #             current_input = f"The previous action failed with error: {str(e)}. Please attempt an alternative path."
        
    #     return "Task could not be completed within the iteration limit."

    # async def execute_tool(self, name: str, args: dict):
    #     from tools.registry import get_tools
        
    #     active_tools = get_tools([name])
    #     if not active_tools:
    #         return f"Error: Tool '{name}' not found in registry."
            
    #     tool_instance = active_tools[0]
        
    #     try:
    #         if hasattr(tool_instance, 'run'):
    #             return await tool_instance.run(**args)
    #         elif hasattr(tool_instance, 'execute'):
    #             return await tool_instance.execute(**args)
    #         else:
    #             return f"Error: Tool '{name}' has no run/execute method."
    #     except Exception as e:
    #         return f"Tool Execution Failed: {str(e)}"
    