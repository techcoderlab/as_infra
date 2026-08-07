import asyncio
from services.strategies.cloudflare_strategy import CloudflareStrategy

async def main():
    strategy = CloudflareStrategy()
    # The API key the user is using is "<token>|<account_id>"
    # Wait, we need the actual token! I don't know the actual token...
    print("Testing")

asyncio.run(main())
