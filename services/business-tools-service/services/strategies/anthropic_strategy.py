import json
import asyncio
from typing import AsyncGenerator, Any, Optional
from core.logger import mcp_logger
from services.llm import stream_anthropic
from services.strategies.base import LLMStrategy

class AnthropicStrategy(LLMStrategy):
    def __init__(self, max_iterations=30):
        self.max_iterations = max_iterations

    async def run_stream(self, api_key: str, model: str, system_prompt: str, effective_history: list, full_user_message: str, tools: list, context: dict, output_format: str = 'text', thinking_budget: Optional[int] = None, use_stream: bool = True, max_iterations: int = 7) -> AsyncGenerator[dict[str, Any], None]:
        # Prepare base messages
        messages = []
        for msg in effective_history:
            if msg["role"] == "system": continue
            messages.append({
                "role": msg["role"] if msg["role"] != "ai" else "assistant",
                "content": msg["content"]
            }) 

        messages.append({"role": "user", "content": full_user_message})

        iteration = 0
        while iteration < max_iterations:
            iteration += 1
            try:
                stream = await stream_anthropic(api_key, model, system_prompt, messages, tools)
                
                # Tracking state for the current response
                final_content = []      # To store text blocks
                tool_invocations = []   # To store incomplete tool calls: {index: {id, name, args_str}}
                
                async for event in stream:
                    if event.type == "content_block_start":
                        if event.content_block.type == "tool_use":
                            # storage for new tool call
                            tool_invocations.append({
                                "id": event.content_block.id,
                                "name": event.content_block.name,
                                "args": ""
                            })
                            
                    elif event.type == "content_block_delta":
                        if event.delta.type == "text_delta":
                            text = event.delta.text
                            yield {"type": "token", "data": text}
                            final_content.append(text)
                            
                        elif event.delta.type == "input_json_delta":
                            # Append to the last tool invocation
                            if tool_invocations:
                                tool_invocations[-1]["args"] += event.delta.partial_json

                # stream finished for this turn
                
                # 1. Check if we have tool calls
                if not tool_invocations:
                    yield {"type": "done"}
                    break
                
                # 2. Append Assistant Message to History
                
                # Construct tool_calls list for the message
                tool_calls_data = []
                for tc in tool_invocations:
                    tool_calls_data.append({
                        "id": tc['id'],
                        "type": "function",
                        "function": {
                            "name": tc['name'],
                            "arguments": tc['args']
                        }
                    })

                assistant_msg = {
                    "role": "assistant",
                    "content": "".join(final_content) if final_content else None,
                    "tool_calls": tool_calls_data
                }
                messages.append(assistant_msg)

                # 3. Execute Tools
                for tool_call in tool_calls_data:
                    tool_name = tool_call['function']['name']
                    call_id = tool_call['id']
                    args_str = tool_call['function']['arguments']
                    
                    try:
                        args = json.loads(args_str)
                        yield {"type": "tool_start", "data": {"tool": tool_name, "args": args}}
                        
                        tool_instance = next((t for t in tools if t.name == tool_name), None)
                        if tool_instance:
                            result = await tool_instance.run(**args, context=context)
                        else:
                            result = f"Error: Tool {tool_name} not found."

                        yield {"type": "tool_end", "data": {"tool": tool_name, "result": result}}
                        
                        # Add Result to History (OpenAI Style)
                        messages.append({
                            "role": "tool",
                            "tool_call_id": call_id,
                            "content": json.dumps(result) if not isinstance(result, str) else result
                        })
                        
                    except Exception as e:
                        error_msg = f"Tool Execution Error: {str(e)}"
                        mcp_logger.error(f"Anthropic Tool Error ({tool_name}): {e}")
                        messages.append({"role": "tool", "tool_call_id": call_id, "content": error_msg})

            except Exception as e:
                mcp_logger.error(f"Anthropic Loop Error: {e}")
                if "429" in str(e):
                    if iteration < 2:
                        await asyncio.sleep(5)
                        continue
                yield {"type": "error", "data": str(e)}
                return

        if iteration >= max_iterations:
            yield {"type": "error", "data": "Max iterations reached (Anthropic Loop)."}
