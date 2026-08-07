import asyncio
import httpx
import json

async def main():
    url = "https://api.cloudflare.com/client/v4/accounts/95b744e0cf40214e3dd1d616c9bd7c4e/ai/v1/chat/completions"
    headers = {
        "Authorization": "Bearer [TOKEN]",
        "Content-Type": "application/json",
    }
    # Test normal tool response
    payload = {
        "model": "@cf/meta/llama-3.1-8b-instruct-fp8",
        "messages": [
            {"role": "user", "content": "Update the lead 123 to hot."},
            {
                "role": "assistant", 
                "tool_calls": [{"id": "call_1", "type": "function", "function": {"name": "update_lead", "arguments": "{}"}}]
            },
            {"role": "tool", "tool_call_id": "call_1", "content": "SUCCESS: Lead updated."}
        ]
    }
    # Test error tool response
    payload2 = {
        "model": "@cf/meta/llama-3.1-8b-instruct-fp8",
        "messages": [
            {"role": "user", "content": "Update the lead 123 to hot."},
            {
                "role": "assistant", 
                "tool_calls": [{"id": "call_1", "type": "function", "function": {"name": "update_lead", "arguments": "{}"}}]
            },
            {"role": "tool", "tool_call_id": "call_1", "content": "Tool 'update_lead' raised TypeError: run() got an unexpected keyword argument 'temperature'"}
        ]
    }
    
    async with httpx.AsyncClient() as client:
        try:
            resp = await client.post(url, headers=headers, json=payload)
            print("Status 1:", resp.status_code)
            print("Body 1:", resp.text)
            
            resp2 = await client.post(url, headers=headers, json=payload2)
            print("Status 2:", resp2.status_code)
            print("Body 2:", resp2.text)
        except Exception as e:
            print(e)

asyncio.run(main())
