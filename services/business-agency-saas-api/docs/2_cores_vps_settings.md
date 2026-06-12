# Infrastructure Optimization Report (2 Core VPS)

## 1. Analysis of the Current Infrastructure
Based on your [docker-compose.yml](file:///Users/hassan/Documents/MyStuff/projects/as-microservices-repo/infra/docker-compose.yml), logs, and configurations:
- **Available Hardware:** 2 Cores, likely 2-4GB RAM (cheap VPS).
- **Architecture Flow:** Laravel pushes an AI processing job → Payload is posted to Sidecar API `/agent/enqueue` → Sidecar API accepts immediately and creates a Python background thread → Sidecar processes (taking 3-10 seconds per your logs) → Sidecar POSTs Webhook back to Laravel `ai-result` callback.
- **Current Queue Workers:**
  - `agency-saas-worker-default`: Fast web tasks (0.50 CPUs, 1GB RAM)
  - `agency-saas-worker-ai`: Heavy AI tasks (0.80 CPUs, 2GB RAM)

### What's Working Well
Moving to the webhook (enqueuing) logic in the Sidecar is the **correct strategy** for serious scaling. Your Laravel workers are no longer blocked waiting for the LLM to finish streaming. They drop the payload off and instantly pick up the next job. This prevents worker saturation.

### What Needs Fixing for a 2-Core Server
When using a 2-Core Server, your Docker absolute CPU limits are dangerously over-provisioned:
- Database, Redis, Nginx (Gateway) = Uncapped (Assume ~0.50 CPU under load)
- Laravel API = 0.75 CPU
- Default Worker = 0.50 CPU
- AI Worker = 0.80 CPU
- Tools Service = Uncapped (Often eats 0.50+ CPU when managing async HTTP streams).

Total possible CPU request = ~3.05+ Cores out of 2.0 Cores. **This will cause your server to freeze or throw 502 Bad Gateways under load.**

## 2. Load Capacity and Scaling

Because you are using asynchronous webhooks, the bottleneck is **no longer PHP/Laravel**, but rather the **Python Sidecar's HTTP throughput** and the **External LLM Rate Limits**.

Currently, your Sidecar is processing a request in ~3 seconds (Gemini Flash Lite).
If 10 leads reply to your WhatsApp bot simultaneously:
- Laravel puts 10 jobs in the Redis queue instantly.
- The `agency-saas-worker-ai` picks up the jobs and shoots 10 HTTP requests to the Sidecar in less than 1 second.
- The Sidecar's FastAPI `BackgroundTasks` will spawn 10 concurrent threads. 

**Maximum Load Estimate:**
FastAPI (Uvicorn) with 1 worker on a 2-core machine can handle about **20-30 concurrent AI streaming connections** before memory spikes or the Uvicorn event loop begins to lag, causing timeouts.

If you exceed 30 concurrent chats, you will need to scale horizontally or implement a queue/semaphore inside the Python Sidecar itself to buffer incoming requests.

## 3. Recommended Fixes

### Fix A: Re-balance CPU/Memory Limits in [docker-compose.yml](file:///Users/hassan/Documents/MyStuff/projects/as-microservices-repo/infra/docker-compose.yml)
Since it's a 2-Core cheap VPS, we must throttle services to prevent hard-crashes.
Instead of `0.75`, `0.50`, `0.80`, we need to dial back the background workers to leave room for the live API and Nginx.

### Fix B: Uvicorn Workers for Sidecar
Currently, the Sidecar runs using `uvicorn.run(...)` which defaults to 1 single-threaded worker. We need to switch this to use `gunicorn` with Uvicorn workers (as hinted in your [main.py](file:///Users/hassan/Documents/MyStuff/projects/as-microservices-repo/services/business-tools-service/main.py) comments) to utilize the 2 CPU cores properly.

### Fix C: Prevent Redis Memory Leaks
Your Redis maxclients is set to `10000`, and memory is limited to `6G` (in your compose file). If the server only has 2-4GB RAM, setting a 6G limit on Redis means the OS will OOM kill Redis. We must set it to `512M` and configure an eviction policy.

---

*I will implement these fixes now in your [docker-compose.yml](file:///Users/hassan/Documents/MyStuff/projects/as-microservices-repo/infra/docker-compose.yml) and Python [server.py](file:///Users/hassan/Documents/MyStuff/projects/as-microservices-repo/services/business-tools-service/api/server.py).*
