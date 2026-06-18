# Agency SaaS Platform — Developer API Reference
## Part 0: System Overview, Architecture & Global Conventions

> Source repository: `techcoderlab/as_infra`
> This is **Part 0** of a multi-part, module-by-module API reference. Each subsequent part documents one service/module in full detail (endpoints, payloads, auth, curl examples, edge cases). This part covers everything that is shared across modules so it isn't repeated 20 times.

---

## 1. High-Level Architecture

The platform is a **multi-tenant Agency SaaS** built as a small set of cooperating services, all fronted by a single Nginx **API Gateway**.

```
                                   ┌───────────────────────────┐
                                   │        Nginx Gateway        │
                                   │  (gateway/nginx.conf.tpl)   │
                                   └──────────────┬──────────────┘
                                                   │
        ┌──────────────────────────────────────────────────────────────────────┐
        │                          │                          │                  │
        ▼                          ▼                          ▼                  ▼
┌──────────────────┐   ┌────────────────────────┐   ┌────────────────┐  ┌──────────────┐
│ business-agency-  │   │ business-tools-service │   │  n8n            │  │ SPA (Vue/Vite)│
│ saas-api (Laravel)│◄──┤ (FastAPI Python Sidecar)│   │  (automation)   │  │  /            │
│  PHP-FPM :9000     │   │  :8017                  │   │  /n8n/          │  │  (served by   │
│  routes: /api/*    │   │  routes: /tools/*       │   │                 │  │  gateway root)│
└─────────┬──────────┘   └───────────┬─────────────┘   └─────────────────┘  └──────────────┘
          │                            │
          │   Redis (cache, queues,    │
          │   rate limiting, HMAC      │
          │   secret cache)            │
          ▼                            ▼
   PostgreSQL (primary DB)     LLM Providers (OpenAI, Gemini,
   Horizon / Queue Workers      Anthropic) + WhatsApp Cloud API +
   (agency-saas-worker-default, Google Sheets/Business APIs
    agency-saas-worker-ai)
```

### 1.1 Core Components

| Component | Tech | Responsibility |
|---|---|---|
| **business-agency-saas-api** | Laravel 11 (PHP 8.2+), Sanctum, Spatie Permission, PostgreSQL | Source of truth. Multi-tenant business logic, auth, leads/CRM, forms, webhooks, AI agent configuration, billing/plan gating, queue dispatch. |
| **business-tools-service** | Python 3.11+, FastAPI, async httpx, Redis | Stateless "AI sidecar". Executes LLM agent loops (tool calling), streams chat (SSE), sends WhatsApp messages, and posts results back to Laravel via signed webhooks. Offloads slow/long-running AI work so the PHP web threads stay fast. |
| **gateway** | Nginx | Single entry point. Routes `/` → Laravel public (SPA + PHP-FPM), `/tools/*` → FastAPI sidecar, `/n8n/*` → n8n automation engine. |
| **spa** | Vue 3 + Vite + Tailwind | Frontend single-page application (admin dashboard, leads, agents, chat widgets). |
| **Redis** | Redis | Cache, queue broker (Horizon), rate-limit buckets, HMAC secret cache for the sidecar's multi-tenant auth. |
| **PostgreSQL** | Postgres | Primary relational store. Note: many queries use Postgres-specific syntax (`ILIKE`, `FILTER`, `::text`, `NULLS LAST`, JSONB casts). |
| **n8n** | n8n | Optional external automation/workflow engine, reachable at `/n8n/`. |

### 1.2 Async / Queue Architecture

Heavy or slow operations (LLM calls, embeddings, document parsing, bulk webhook delivery) are **never** executed inline during an HTTP request. Instead:

1. **Push** — Laravel pushes a serialized Job onto a Redis queue and returns the HTTP response immediately.
2. **Pull** — Dedicated queue workers pull jobs and execute them:
   - `agency-saas-worker-default` — fast business logic (emails, DB updates, webhook fan-out).
   - `agency-saas-worker-ai` — tuned for slow LLM calls (higher memory, longer timeout, lower concurrency).
3. For AI-specific work, Laravel may instead (or additionally) call the **business-tools-service** sidecar's `/v1/agent/enqueue` endpoint, which itself processes in the background and **calls back** to Laravel's `/api/mcp/callback/ai-result` webhook when finished (see Part 10).

---

## 2. Base URLs & Routing (Gateway)

All traffic goes through the Nginx gateway on port 80 (or 443 behind TLS termination). Internally:

| Path prefix | Upstream | Notes |
|---|---|---|
| `/` (everything not matched below) | `business-agency-saas-api` (Laravel `public/`) | Serves the SPA's static build + falls through to `index.php` for `/api/*` routes. PHP requests are passed to PHP-FPM on port `9000`. |
| `/api/*` | Laravel (`routes/api.php`) | The main JSON REST API documented in Parts 1–10. |
| `/tools/*` | `business-tools-service:8017` | Python sidecar REST/SSE API, trailing slash strips `/tools/`. Documented in Part 11. |
| `/tools/v1/agent/*` | `business-tools-service:8017` (same upstream, separate location block) | Gets special **streaming-optimized** Nginx config: buffering off, `proxy_read_timeout 300s`, HTTP/1.1, keep-alive — required for SSE chat. |
| `/n8n/*` | `n8n:5678` | Automation engine UI/API, with WebSocket upgrade support. |
| `/health` (default server, unmatched hosts) | static | Returns `{"status":"healthy"}` — used by load balancers. |

**Example base URL used throughout this documentation** (replace with your real domain):

```
https://api.youragency.com
```

So an endpoint documented as `POST /leads` is reached at:

```
https://api.youragency.com/api/leads
```

and a sidecar endpoint documented as `POST /v1/agent/enqueue` is reached at:

```
https://api.youragency.com/tools/v1/agent/enqueue
```

---

## 3. Authentication Models

The platform uses **three distinct authentication mechanisms** depending on which "door" you're coming through. Picking the right one is the single most common integration mistake.

### 3.1 Laravel Sanctum Bearer Tokens (Primary — humans & first-party apps)

Used for: SPA login sessions, mobile apps, and developer-issued **API Keys** (which are just scoped Sanctum tokens).

- Obtain via `POST /api/login` or `POST /api/register` (Part 1).
- Send on every authenticated request:

```http
Authorization: Bearer <token>
Accept: application/json
```

- Tokens can be **scoped** with *abilities* (e.g. `leads:view`, `leads:write`). The default `api` token created at login/register has the `*` (all) ability. Developer-issued API Keys (Part 8) can be restricted to specific scopes.
- Tokens may optionally **expire** (`expiration_days`: 30/60/90, or never).

#### Full curl example

```bash
# 1. Login
TOKEN=$(curl -s -X POST https://api.youragency.com/api/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"owner@agency.com","password":"secret123"}' | jq -r '.token')

# 2. Use the token
curl -s https://api.youragency.com/api/bootstrap \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

### 3.2 HMAC-SHA256 Signed Requests (Service-to-Service / External Apps)

Used for:
- Calls **from** the Laravel API **to** the `business-tools-service` sidecar (`/tools/v1/agent/*`, `/tools/v1/wa/*`).
- Calls **into** Laravel's "MCP bridge" endpoints under `VerifyExternalAppSignature` (e.g. `/api/mcp/callback/ai-result`, `/api/internal/leads/...`).
- Any third-party/external app issued an **External API Key** (Part 8.2) — these are platform-level (not tenant-scoped) credentials stored in `external_api_keys` and mirrored into Redis (`apikey:{app_id}`) for fast lookup.

#### Required headers

| Header | Description |
|---|---|
| `X-App-Id` | Public application identifier, format `app_<16 hex chars>` |
| `X-Timestamp` | Current Unix timestamp (seconds, fractional allowed) |
| `X-Signature` | `hex(HMAC_SHA256(secret, timestamp + raw_json_body))` |

#### Rules

- **Replay protection**: requests where `abs(now() - X-Timestamp) > 60` seconds are rejected with `401`.
- The signature is computed over the **exact raw request body string concatenated after the timestamp string** — i.e. `signature = HMAC_SHA256(secret, "{timestamp}{raw_body_json}")`. Whitespace/ordering in the JSON matters — sign the *exact bytes* you send.
- The secret is looked up by `X-App-Id`:
  - Sidecar (`business-tools-service`) looks it up at `redis: agency-saas-api-database-apikey:{app_id}`.
  - Laravel's `VerifyExternalAppSignature` middleware looks it up at `redis: apikey:{app_id}`, falling back to decrypting `external_api_keys.secret` in Postgres if Redis misses.

#### Reference implementation (Node.js)

```javascript
const crypto = require('crypto');

function generateHmacHeaders(appId, secret, payloadObjOrString) {
  const timestamp = (Date.now() / 1000).toString();
  const payloadString = typeof payloadObjOrString === 'string'
    ? payloadObjOrString
    : JSON.stringify(payloadObjOrString);

  const message = timestamp + payloadString;
  const signature = crypto.createHmac('sha256', secret).update(message, 'utf8').digest('hex');

  return {
    'X-App-Id': appId,
    'X-Timestamp': timestamp,
    'X-Signature': signature,
    'Content-Type': 'application/json',
  };
}
```

#### Reference implementation (bash / curl)

```bash
APP_ID="app_c8df99680e63c5de"
SECRET="sk_live_31d6451f8c8cc626d481d567e7faaa08"
PAYLOAD='{"hello":"world"}'

TIMESTAMP=$(date +%s.%3N)
MESSAGE="${TIMESTAMP}${PAYLOAD}"
SIGNATURE=$(echo -n "$MESSAGE" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -X POST "https://api.youragency.com/tools/v1/agent/enqueue" \
  -H "Content-Type: application/json" \
  -H "X-App-Id: $APP_ID" \
  -H "X-Timestamp: $TIMESTAMP" \
  -H "X-Signature: $SIGNATURE" \
  -d "$PAYLOAD"
```

### 3.3 Signed URLs (Laravel `signed` middleware)

Used for the **AI chat SSE stream** endpoint only:

```
GET /api/ai-chats/{user}/{aiChat}/chat-stream
```

This URL is generated server-side via `URL::temporarySignedRoute(...)` (valid 5 minutes) and returned by `POST /ai-chats/{aiChat}/message` as `stream_url`. You do **not** construct this signature yourself — you receive a ready-to-fetch URL and open it directly (e.g. with `EventSource`). See Part 6.

### 3.4 Outgoing Webhook Signatures (Tenant Webhooks)

When **your** registered webhook (Part 5) is called by the platform (e.g. on `lead.created`), the request includes:

```http
X-Webhook-Signature: <hex hmac_sha256(json_encode(payload), your_webhook_secret)>
User-Agent: AgencySaaS-Webhook/1.0
Content-Type: application/json
```

To verify on your end:

```javascript
const crypto = require('crypto');
const expected = crypto.createHmac('sha256', WEBHOOK_SECRET).update(rawJsonBody).digest('hex');
if (expected !== req.headers['x-webhook-signature']) throw new Error('Invalid signature');
```

> Note: unlike the sidecar HMAC scheme, **outgoing tenant webhooks do not prepend a timestamp** — the signature is purely `HMAC_SHA256(secret, json_body)`.

---

## 4. Multi-Tenancy Model

This is a **single-database, shared-schema multi-tenant** system.

- Every tenant-scoped model (Leads, Forms, Webhooks, AI Agents, Integrations, TenantSettings, ApiAuditLog, etc.) carries a `tenant_id` column and uses the `BelongsToTenant` trait, which applies a **global Eloquent scope** automatically filtering queries to `tenant_id = auth()->user()->current_tenant_id`.
- A `User` can belong to **multiple tenants** (`tenant_user` pivot table with `role` and `is_primary`), but always operates within **one "current" tenant** at a time, tracked by `users.current_tenant_id`.
- Switching tenant context happens via the `EnsureUserHasTenantAccess` middleware, which reads `X-Tenant-Id` header (or a `tenant_id` route param), checks an `access-tenant` Gate, and updates `current_tenant_id`.
- **Roles** (via Spatie Permission): `super_admin` (platform operator — bypasses all tenant/module checks), `agency_owner` (tenant owner, assigned at registration), and other tenant-staff roles.

### Standard authenticated middleware stack

Almost every `/api/*` route (except `/login`, `/register`, `/public/*`, and signed/HMAC routes) runs through this group:

```php
Route::middleware([
  'auth:sanctum',          // Bearer token required
  'throttle:tenant_api',   // Rate limiting (tenant-aware)
  'check.status',          // Tenant not suspended
  'log.api',               // Writes to api_audit_logs
  'plan.expiry',           // Subscription not expired (402 if so)
  'check.tenant_access',   // Resolves X-Tenant-Id / tenant context
])->group(function () { ... });
```

Many route groups additionally apply `check.module:<slug>` (see §5).

---

## 5. Plans & Module Gating

Access to feature areas is governed by a **Plan → Modules** relationship (many-to-many via `module_plan`, with a per-module `limit` pivot value).

- **Modules** (`modules` table) — feature flags such as `leads`, `forms`, `webhooks`, `ai_chats`, `ai_agents`, `api_keys`, `integrations`.
- **Plans** (`plans` table) — named bundles (e.g. "Starter", "Pro") each with `ai_credit_limit` and a set of modules + per-module limits.
- **Tenants** subscribe to exactly one active **Plan** via `plan_tenant` (pivot: `expires_at`, `grace_period_days`, `ai_credits_used`).

### Enforcement points

1. **`CheckTenantModule` middleware** (`check.module:<slug>`) — returns `403` with `"The {slug} module is not included in your current plan."` if the tenant's active plan doesn't include that module.
2. **`CheckPlanExpiry` middleware** (`plan.expiry`) — if `now() > expires_at + grace_period_days`, returns:
   ```json
   { "error": "subscription_expired", "message": "Your access has been suspended on 12 Jan, 2026. Please renew your subscription to regain access." }
   ```
   with HTTP `402 Payment Required`. Super admins bypass this.
3. **Per-resource limits** — e.g. `LeadPolicy::create()` calls `TenantManager::checkLimit('leads', Lead::class)`; if the tenant's plan module limit (`-1` = unlimited, `0` = disabled, `N` = cap) is reached, creation is denied via policy (`403`).
4. **AI credit limits** — `Tenant::canTenantUseAi()` checks `plan_tenant.ai_credits_used` against the plan's `ai_credit_limit` (`-1` = unlimited, `0` = disabled).

### Cache busting

Tenant module/plan data is cached (`TenantManager`). Whenever a Super Admin updates a Plan or assigns a Plan to a Tenant, the API explicitly calls `clearTenantCache($tenantId)` so the next request reflects new limits immediately — no propagation delay.

---

## 6. Standard Response & Error Conventions

### 6.1 Success responses

- Collection endpoints generally return **Laravel pagination envelopes**:
  ```json
  {
    "data": [ /* ... */ ],
    "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
    "meta": { "current_page": 1, "from": 1, "last_page": 5, "per_page": 20, "to": 20, "total": 97 }
  }
  ```
- Some endpoints return **plain arrays/objects** (not paginated) — e.g. `GET /forms`, `GET /webhooks`, `GET /tenants/modules`. Check each endpoint's documented response shape.
- Mutation endpoints typically return `{"message": "...", "id": <id>}` (HTTP 200/201) or the full updated resource.

### 6.2 Error responses

| HTTP Code | Meaning | Typical body |
|---|---|---|
| `401` | Unauthenticated (missing/invalid Sanctum token, or invalid HMAC) | `{"message": "Unauthenticated."}` or `{"error": "Unauthorized: ..."}` |
| `403` | Forbidden — policy/permission failure, module not in plan, suspended tenant | `{"message": "This action is unauthorized."}` / `{"message": "The {module} module is not included in your current plan."}` |
| `404` | Resource not found / route model binding miss | `{"message": "No query results for model [...]"}` |
| `409` | Conflict — e.g. deleting a Plan/Tenant still in use | `{"message": "Cannot delete this plan because it is currently assigned to tenants..."}` |
| `422` | Validation error (Laravel FormRequest) | `{"message": "The given data was invalid.", "errors": {"field": ["..."]}}` |
| `402` | Subscription expired | `{"error": "subscription_expired", "message": "..."}` |
| `429` | Rate limited (tenant_api throttle, or sidecar per-tenant agent rate limit) | `{"error": "Too many requests", "message": "Tenant ... rate limit reached. Please try again in Ns."}` (sidecar) |
| `500` | Unhandled server error | `{"error": "Internal Server Error"}` or `{"status":"error","message":"Internal Server Error","detail":"..."}` (sidecar) |

### 6.3 Pagination query params (list endpoints)

| Param | Type | Default | Notes |
|---|---|---|---|
| `per_page` | int | `20` | Clamped to `[5, 100]` on Leads endpoint; varies elsewhere |
| `page` | int | `1` | Standard Laravel pagination |

### 6.4 Date/Time conventions

- All timestamps are returned in ISO-8601 / `Y-m-d H:i:s` (Carbon defaults), UTC unless the server timezone is configured otherwise.
- Date-only filter params (`date_from`, `date_to`, `start_date`, `end_date`) accept `Y-m-d`.

---

## 7. Module Index (What's in Each Part)

| Part | Module | Service | Key Endpoints |
|---|---|---|---|
| **1** | Auth & Bootstrap | Laravel | `POST /login`, `POST /register`, `GET /bootstrap` |
| **2** | Tenants, Plans & Modules (Super Admin) | Laravel | `/tenants`, `/plans-data`, `/plans`, `/modules`, `/tenants/{id}/assign-plan` |
| **3** | Leads (CRM) | Laravel | `/leads`, `/leads/stats`, `/leads/batch`, `/leads/import`, `/leads/export` |
| **4** | Forms (incl. Public submission) | Laravel | `/forms`, `/public/form/{uuid}`, `/public/tally/form/submit`, `/public/external/form/submit` |
| **5** | Webhooks | Laravel | `/webhooks` |
| **6** | AI Chats (chat widgets + SSE) | Laravel | `/ai-chats`, `/ai-chats/{id}/message`, `/ai-chats/{user}/{id}/chat-stream` |
| **7** | AI Agents & Integrations (credentials) | Laravel | `/ai-agents`, `/integrations`, `/integrations/available`, `/ai-settings` |
| **8** | API Keys (tenant) & External API Keys (platform) | Laravel | `/api-keys`, `/external-api-keys` |
| **9** | Channel Integrations: WhatsApp & Google Business Profile | Laravel | `/integrations/whatsapp/webhook/{tenant}`, `/google-business/connect`, `/google-business/callback` |
| **10** | AI Jobs, MCP Bridge & Sidecar Webhooks | Laravel | `/ai-jobs/{target_id}/monitor`, `/mcp/callback/ai-result`, `/internal/leads/...` |
| **11** | Business Tools Service (Python Sidecar) | FastAPI | `/v1/agent/run`, `/v1/agent/chat`, `/v1/agent/enqueue`, `/v1/wa/send`, `/health` |
| **12** | Gateway & Infrastructure | Nginx/Docker | Routing rules, docker-compose, deployment notes |

---

## 8. Conventions Used Throughout This Reference

- `{base_url}` = your gateway domain, e.g. `https://api.youragency.com`.
- All Laravel endpoints assume the header block:
  ```http
  Authorization: Bearer <token>
  Accept: application/json
  Content-Type: application/json
  ```
  unless otherwise noted (e.g. file uploads use `multipart/form-data`, public endpoints need no auth).
- `@authenticated` annotation in a section means the standard Sanctum middleware stack from §4 applies (and therefore plan/module gating may apply too).
- Where a required **module** is noted (e.g. "Requires `leads` module"), the tenant's active Plan must include that module or you'll get a `403`.

---

**Next:** Part 1 — Authentication & Bootstrap Module.

> Reply "continue" (or similar) and I'll proceed to **Part 1: Auth & Bootstrap**.
