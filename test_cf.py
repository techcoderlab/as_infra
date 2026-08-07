import asyncio
import httpx
import json

async def main():
    url = "https://api.cloudflare.com/client/v4/accounts/95b744e0cf40214e3dd1d616c9bd7c4e/ai/v1/chat/completions"
    headers = {
        "Authorization": "Bearer fake_token",
        "Content-Type": "application/json",
    }
    payload = {
        "model": "@cf/meta/llama-3.1-8b-instruct-fp8",
        "messages": [{"role": "user", "content": "hi"}]
    }
    async with httpx.AsyncClient() as client:
        try:
            resp = await client.post(url, headers=headers, json=payload)
            print("Status:", resp.status_code)
            print("Body:", resp.text)
        except Exception as e:
            print(e)

asyncio.run(main())
