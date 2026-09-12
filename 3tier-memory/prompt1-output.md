Here is the read-only audit report based on the current repository structure:

1. tenant_id Enforcement
@business-agency-saas-api

Global Scopes / Query Builders: The API uses a BelongsToTenant trait (app/Traits/BelongsToTenant.php) that automatically assigns the current tenant ID on creation ($tenantManager->getActiveTenant()) and intercepts database queries to append a global where clause ($builder->where(..., $tenant->id)).
Routing: Explicitly requested in endpoints via {tenant_id} parameters (e.g., /api/tenants/{tenant_id}).
@business-tools-service

Headers & Middleware: Fetched from the X-Tenant-ID header (defaulting to "global") in the FastAPI middleware (api/server.py) specifically to enforce rate-limiting via token buckets on agent endpoints.
Dependency Injection / Context: Tool definitions (e.g., crm_read.py, crm.py) retrieve the tenant ID from the execution context (context.get("global_data", {}).get("tenant_id") or context.get("tenant_id")). The tools then inject this as the x-tenant-id header when making downstream HTTP requests back to the SaaS API.
2. Postgres Schema (Chats / Leads / Tenants)
tenants table

id (bigint, PK)
name (string)
domain (string, nullable, unique)
status (enum: 'active', 'suspended')
enabled_modules (jsonb)
created_at, updated_at
leads table

id (bigint, PK)
tenant_id (bigint, indexed)
form_id (uuid, indexed, nullable)
payload (jsonb)
score (integer, added in a later migration)
created_at, updated_at
ai_chats table

id (bigint, PK)
tenant_id (bigint, indexed, FK to tenants cascading on delete)
ai_agent_id (bigint, FK to ai_agents with nullOnDelete)
name (string)
webhook_url (string)
webhook_secret (string, nullable)
avatar_url (string, nullable)
welcome_message (text, nullable)
created_at, updated_at
3. pgvector Extension & Vector Columns
Status: pgvector is not enabled.
Columns: There are currently no vector columns anywhere in the database schema. No embeddings or similarity search functionality is currently defined at the data layer.
4. FastAPI Routes & Tool Calling (@business-tools-service)
FastAPI LLM Routes (api/routers/agent.py)

POST /v1/agent/run: A synchronous endpoint that buffers the LLM stream and returns the final JSON response (designed for Laravel Jobs).
POST /v1/agent/chat: An asynchronous endpoint that yields a Server-Sent Events (SSE) stream (designed for the Frontend Chat UI).
POST /v1/agent/enqueue: A fire-and-forget asynchronous endpoint. It accepts a job, returns a 202-style acknowledgment immediately, and processes the agent run in BackgroundTasks (guarded by a concurrency semaphore). Results are delivered via a webhook callback payload.
Tool Calling Logic

Tools inherit execution context securely and format outgoing HTTP calls to the internal services.
The AgentService orchestrates execution, while the callback mechanism (services.webhook_callback.py) enforces strict SSRF protections when posting final tool/LLM outputs back to the requester.
5. Chat History Storage
Table Name: chat_messages
Columns:
id (bigint, PK)
ai_chat_id (bigint, FK to ai_chats)
user_id (bigint, FK to users for privacy linking)
role (string: 'user' or 'ai')
content (longText, nullable)
files (jsonb, nullable)
created_at, updated_at
Retention: Indefinite, tied tightly to relational constraints. Chat history is preserved even if the linked AI Agent is deleted (the ai_agent_id simply nullifies). However, if the parent ai_chat or the specific user_id is deleted, the messages are hard-deleted via an onDelete('cascade') rule.