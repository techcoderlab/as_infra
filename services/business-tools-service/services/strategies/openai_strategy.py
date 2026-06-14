import json
import asyncio
from typing import AsyncGenerator, Any, Optional
from core.logger import mcp_logger
from services.llm import chat_with_openai
from services.strategies.base import LLMStrategy

class OpenAIStrategy(LLMStrategy):
    def __init__(self, max_iterations=30):
        self.max_iterations = max_iterations

    async def execute(self, api_key: str, model: str, system_prompt: str, effective_history: list, full_user_message: str, tools: list, context: dict, output_format: str = 'text', thinking_budget: Optional[int] = None, use_stream: bool = True, max_iterations: int = 7) -> AsyncGenerator[dict[str, Any], None]:
        messages = []
        for msg in effective_history:
            if msg["role"] == "system":
                continue
            messages.append({
                "role": msg["role"] if msg["role"] != "ai" else "assistant",
                "content": msg["content"]
            })

        messages.append({"role": "user", "content": full_user_message})
        
        iteration = 0
        
        while iteration < max_iterations:
            iteration += 1
            try:
                stream = await chat_with_openai(api_key, model, system_prompt, messages, tools)
                
                tool_calls = []
                current_tool_call = None
                
                async for chunk in stream:
                    if not chunk.choices: continue
                    delta = chunk.choices[0].delta
                    
                    if delta.content:
                        yield {"type": "token", "data": delta.content}
                    
                    if delta.tool_calls:
                        for tc in delta.tool_calls:
                            if tc.id:
                                if current_tool_call: tool_calls.append(current_tool_call)
                                current_tool_call = {"id": tc.id, "name": tc.function.name, "args": ""}
                            if tc.function.arguments:
                                current_tool_call["args"] += tc.function.arguments
                
                if current_tool_call: tool_calls.append(current_tool_call)

                if not tool_calls:
                    yield {"type": "done"}
                    break

                # Add assistant's tool call message to history
                assistant_msg = {
                    "role": "assistant", 
                    "tool_calls": [
                        {"id": t['id'], "type": "function", "function": {"name": t['name'], "arguments": t['args']}}
                        for t in tool_calls
                    ]
                }
                messages.append(assistant_msg)

                # Execute Tools
                for tool_call in tool_calls:
                    tool_name = tool_call['name']
                    try:
                        args = json.loads(tool_call['args'])
                        yield {"type": "tool_start", "data": {"tool": tool_name, "args": args}}
                        
                        tool_instance = next((t for t in tools if t.name == tool_name), None)
                        if tool_instance:
                            result = await tool_instance.run(**args, context=context)
                        else:
                            result = f"Error: Tool {tool_name} not found."

                        yield {"type": "tool_end", "data": {"tool": tool_name, "result": result}}
                        
                        messages.append({
                            "role": "tool",
                            "tool_call_id": tool_call['id'],
                            "content": json.dumps(result) if not isinstance(result, str) else result
                        })
                    except Exception as e:
                        mcp_logger.error(f"OpenAI Tool Error ({tool_name}): {e}")
                        messages.append({"role": "tool", "tool_call_id": tool_call['id'], "content": f"Error: {str(e)}"})
            except Exception as e:
                if "429" in str(e):
                    mcp_logger.warning("OpenAI Rate Limit hit. Retrying... " + str(e))
                    if iteration < 2:
                        await asyncio.sleep(5)
                        continue
                return

        if iteration >= max_iterations:
            yield {"type": "error", "data": "Max iterations reached (OpenAI Loop)."}
