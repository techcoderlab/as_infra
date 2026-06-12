# app/services/llm.py
import openai
from google import genai
from google.genai import types
from google.genai import Client
from typing import Optional
from collections.abc import Iterable
# --- NEW: Anthropic Implementation ---
import anthropic
import json

async def stream_openai(api_key, model, system_prompt, messages, tools):
    client = openai.AsyncOpenAI(api_key=api_key)
    
    # 1. Convert our tool objects to OpenAI's expected JSON schema
    openai_tools = [t.to_openai_schema() for t in tools] if tools else None
    
    # 2. Build the messages list properly
    # Ensure system prompt is always at index 0 and not duplicated
    final_messages = [{"role": "system", "content": system_prompt}]
    
    for msg in messages:
        if msg["role"] == "system": continue # Skip if already in history
        final_messages.append(msg)
        
        
    kwargs = {
        "model": model,
        "messages": final_messages,
        "stream": True,
    }

    if openai_tools:
        kwargs.update({
            "tools": openai_tools,
            "tool_choice": "auto",
            "parallel_tool_calls": True,
        })

    # 3. Request the stream
    stream = await client.chat.completions.create(**kwargs)

    return stream

# --- Global Clients (Singleton Pattern) ---
# Reusing clients avoids the overhead of re-initializing gRPC channels/SSL on every request.
# --- Global Client Registry (Multi-Tenant Singleton) ---
_gemini_clients: dict[str, Client] = {}

def get_gemini_client(api_key: str) -> Client:
    global _gemini_clients
    if api_key not in _gemini_clients:
        _gemini_clients[api_key] = Client(api_key=api_key)
    return _gemini_clients[api_key]

# --- NEW: Complete Gemini Implementation using google-genai SDK ---
async def stream_gemini(api_key, model_name, system_prompt, history, tools, json_mode: bool = False, thinking_budget: Optional[int] = None):
    """
    Initializes a Gemini Chat using the new google-genai SDK.
    Returns a Chat object so we can send messages to it.
    """
    # Create client using singleton pattern
    client = get_gemini_client(api_key)
    
    # 1. Convert Tools to new Gemini Format
    # The new SDK accepts function declarations in GenerateContentConfig
    gemini_tool_declarations = None
    if tools:
        # Convert our tool objects to FunctionDeclaration format
        gemini_tool_declarations = []
        for t in tools:
            # t.to_gemini_schema() returns the FULL declaration dict: {name, description, parameters}
            # We unpack it so name/description/parameters are passed to the right fields
            decl = t.to_gemini_schema()
            gemini_tool_declarations.append(types.FunctionDeclaration(**decl))

    # 2. Convert OpenAI-style 'messages' history to Gemini 'history'
    gemini_history = []
    
    if not history or not isinstance(history, Iterable):
        history = []
        
    for msg in history:
        # MAP YOUR DB ROLES TO GEMINI ROLES
        # Laravel 'ai' becomes Gemini 'model'
        # Laravel 'user' stays Gemini 'user'
        role = "model" if msg["role"] in ["ai", "model", "assistant"] else "user"
        
        if msg["role"] == "system":
            continue
            
        # Standardize parts
        parts = []
        if "content" in msg and msg["content"]:
            parts.append(types.Part.from_text(text=msg["content"]))
        
        # If the history message was a tool result (FunctionResponse)
        if msg["role"] == "tool": # It will never execute because db doesn't contain "tool" role.
            gemini_history.append(
                types.Content(
                    role="function",  # Gemini specific role for tool results
                    parts=[
                        types.Part.from_function_response(
                            name=msg.get("tool_name"),
                            response={"content": msg.get("content")}
                        )
                    ]
                )
            )
            continue

        if parts:
            gemini_history.append(types.Content(role=role, parts=parts))

    # 3. Create chat config with tools and system instruction
    chat_config = None
    if gemini_tool_declarations or system_prompt or json_mode or thinking_budget is not None:
        config_kwargs = {}
        if json_mode and not gemini_tool_declarations:
            config_kwargs["response_mime_type"] = "application/json"
        
        if thinking_budget is not None and thinking_budget > 0:
            # P4 Performance: thinking_budget caps internal reasoning to prevent high TTFT
            # include_thoughts=False significantly reduces TTFT as thoughts aren't streamed
            config_kwargs["thinking_config"] = types.ThinkingConfig(include_thoughts=False, thinking_budget=thinking_budget)
            # When thinking is enabled, temperature must be 1.0 (SDK requirement)
            config_kwargs["temperature"] = 1.0
        elif thinking_budget == 0:
            # Explicitly disable thinking for maximum speed
            config_kwargs["thinking_config"] = None
        
        if system_prompt:
            config_kwargs["system_instruction"] = system_prompt
        if gemini_tool_declarations:
            config_kwargs["tools"] = [types.Tool(function_declarations=gemini_tool_declarations)]
            # Disable automatic function calling - we handle it ourselves
            config_kwargs["automatic_function_calling"] = types.AutomaticFunctionCallingConfig(
                # disable=True,
                # maximum_remote_calls=0
                maximum_remote_calls=10
            )
        chat_config = types.GenerateContentConfig(**config_kwargs)

    # 4. Create async chat session with history
    chat = client.aio.chats.create(
        model=model_name,
        history=gemini_history if gemini_history else None,
        config=chat_config
    )
    
    return chat


async def stream_anthropic(api_key, model, system_prompt, messages, tools):
    client = anthropic.AsyncAnthropic(api_key=api_key)
    
    # 1. Convert tools if present
    anthropic_tools = [t.to_anthropic_schema() for t in tools] if tools else anthropic.NotGiven()

    # 2. Build messages list with robust OpenAI -> Anthropic conversion
    final_messages = []

    def get_last_msg(role):
        if final_messages and final_messages[-1]['role'] == role:
            return final_messages[-1]
        return None

    for msg in messages:
        role = msg.get("role")
        content = msg.get("content")
        
        if role == "system": 
            continue 

        # Map 'ai'/'model' to 'assistant'
        if role in ["ai", "model"]: role = "assistant"
        
        if role == "tool":
            # Handle OpenAI 'tool' role -> Anthropic 'user' role with 'tool_result' block
            tool_call_id = msg.get("tool_call_id")
            content_str = str(content) if content is not None else ""
            
            block = {
                "type": "tool_result",
                "tool_use_id": tool_call_id,
                "content": content_str
            }
            
            # Merge into previous USER message if possible, or create new one
            last_msg = get_last_msg("user")
            if last_msg:
                if isinstance(last_msg['content'], list):
                    last_msg['content'].append(block)
                else:
                    # Convert string content to block list
                    last_msg['content'] = [{"type": "text", "text": last_msg['content']}, block]
            else:
                final_messages.append({"role": "user", "content": [block]})
            
        elif role == "assistant":
            # Handle assistant messages with potential tool_calls
            tool_calls = msg.get("tool_calls")
            blocks = []
            
            if content:
                blocks.append({"type": "text", "text": content})
            
            if tool_calls:
                for tc in tool_calls:
                    # Parse arguments: OpenAI stores them as JSON string
                    try:
                        args = json.loads(tc['function']['arguments'])
                    except (json.JSONDecodeError, TypeError):
                        args = {}
                        
                    blocks.append({
                        "type": "tool_use",
                        "id": tc['id'],
                        "name": tc['function']['name'],
                        "input": args
                    })
            
            # If nothing (rare case of empty assistant msg), skip or allow? Anthropic dislikes empty msgs.
            if blocks:
                final_messages.append({"role": "assistant", "content": blocks})
            
        else: # user
            final_messages.append({"role": "user", "content": content})

    # 3. Stream Request
    stream = await client.messages.create(
        model=model,
        max_tokens=4096,
        system=system_prompt,
        messages=final_messages,
        tools=anthropic_tools,
        stream=True
    )

    return stream