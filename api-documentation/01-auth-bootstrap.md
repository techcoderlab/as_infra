# Agency SaaS Platform — Developer API Reference
## Part 1: Authentication & Bootstrap Module

> **Service:** `business-agency-saas-api` (Laravel)
> **Controllers:** `App\Http\Controllers\AuthController`, `App\Http\Controllers\BootstrapController`
> **Base path:** `{base_url}/api`
> See **Part 0** for global conventions (auth headers, error formats, multi-tenancy).

---

## 1. Module Overview

This module handles:

1. **Account registration** — creates a brand-new **Tenant (Agency)** + its first **User** (`agency_owner`) in one atomic transaction.
2. **Login** — exchanges email/password for a Sanctum bearer token.
3. **Bootstrap** — the single "give me everything the frontend needs to render" endpoint, called immediately after login/app-load. Returns the current user, their permissions, the active tenant, enabled modules, and a navigation manifest.
4. *(Disabled/commented in routes, documented for completeness)* **n8n token issuance** — would mint a scoped token for n8n webhook calls.

No module gating applies to this module — it is the entry point before any tenant context exists (registration) or runs under the standard authenticated stack (bootstrap).

---

## 2. `POST /register`

Creates a new Tenant (Agency) and its owning User in a single DB transaction, assigns the `agency_owner` role, and returns an API bearer token. **No authentication required.**

### Authorization
- Public (no `Authorization` header needed).

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation | Description |
|---|---|---|---|---|
| `tenant_name` | string | ✅ | `max:255` | Display name of the new agency/tenant |
| `tenant_domain` | string | ❌ | `max:255`, unique in `tenants.domain` | Optional custom domain identifier |
| `name` | string | ✅ | `max:255` | Full name of the first user (owner) |
| `email` | string | ✅ | valid email, unique in `users.email`, `max:255` | Login email |
| `password` | string | ✅ | `min:8`, must be **confirmed** (`password_confirmation` field required) | Account password |

### What happens internally

1. A new `Tenant` row is created with `status = 'active'`.
2. A new `User` row is created (password hashed via model cast).
3. The user is attached to the tenant via `tenant_user` pivot with `role = 'agency_owner'`, `is_primary = true`.
4. `users.current_tenant_id` is set to the new tenant.
5. Spatie role `agency_owner` is assigned to the user.
6. A Sanctum token named `api` (default ability `['*']`) is created.

> ⚠️ At this point the new tenant has **no Plan assigned**. Until a Super Admin assigns a plan (Part 2), most module-gated endpoints will return `403`/`402`. Bootstrap and basic auth will still work.

### Response

**`201 Created`**
```json
{
  "token": "1|s0meRandomPlainTextSanctumToken..."
}
```

**`422 Unprocessable Entity`** — validation failure
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email has already been taken."],
    "password": ["The password confirmation does not match."]
  }
}
```

### cURL

```bash
curl -X POST "{base_url}/api/register" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "tenant_name": "Acme Marketing Agency",
    "tenant_domain": "acme-marketing",
    "name": "Jane Owner",
    "email": "jane@acme.com",
    "password": "SuperSecret123",
    "password_confirmation": "SuperSecret123"
  }'
```

### Frontend integration notes

- Immediately after registering, call `GET /bootstrap` (§4) to fetch the user/permissions/modules and decide what to render. Since no plan is assigned yet, `enabled_modules` will likely be `[]`.
- Store the returned `token` securely (e.g. `localStorage` for SPA, secure storage for mobile) and attach it as `Authorization: Bearer <token>` on all subsequent requests.

---

## 3. `POST /login`

Authenticates an existing user by email/password and issues a new Sanctum bearer token. **No authentication required.**

### Authorization
- Public (no `Authorization` header needed).

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `email` | string | ✅ | valid email |
| `password` | string | ✅ | string |

### What happens internally

1. Looks up the user by `email`. If not found or password mismatch → `422`.
2. If the user has **no current tenant set** but is attached to at least one tenant, auto-assigns `current_tenant_id` to their first tenant (`tenants()->first()`).
3. Loads `currentTenant`. If `currentTenant.status === 'suspended'` → `403`, with message instructing the user to contact the administrator. **(This check happens at login time, in addition to the per-request `check.status` middleware.)**
4. Issues a new Sanctum token named `api` with ability `['*']` (full access for the primary app session — fine-grained scoping is reserved for developer API keys, see Part 8).

### Response

**`200 OK`**
```json
{
  "token": "2|AnotherPlainTextSanctumToken..."
}
```

**`422 Unprocessable Entity`** — invalid credentials
```json
{
  "message": "Invalid credentials."
}
```

**`403 Forbidden`** — tenant suspended
```json
{
  "message": "Your account is suspended. Please contact the administrator."
}
```

### cURL

```bash
curl -X POST "{base_url}/api/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "jane@acme.com",
    "password": "SuperSecret123"
  }'
```

### Edge cases & developer notes

- **Multiple tenants per user**: a single user account can belong to multiple agencies (multi-agency staff). Login does **not** let you choose which tenant — it defaults to `current_tenant_id` (or the first tenant if unset). To switch tenants post-login, send subsequent requests with header `X-Tenant-Id: <tenant_id>` — the `EnsureUserHasTenantAccess` middleware will validate access (via the `access-tenant` Gate) and update `current_tenant_id` accordingly.
- This endpoint **does not invalidate previous tokens** — each login creates a new `api` token. If you need to manage/revoke sessions, use the API Keys endpoints (Part 8) which operate on the same underlying `personal_access_tokens` table (filtered to exclude the `api` token name for the developer-facing list).
- There is **no `/logout` endpoint** exposed in `routes/api.php`. To "log out", delete the current token client-side and/or call the token revocation endpoint from Part 8 (`DELETE /api-keys/{id}`) — note that endpoint excludes tokens named `api` from listing but a direct DB/token deletion approach would still apply; in practice, clients simply discard the token.

---

## 4. `GET /bootstrap`

The primary "app shell" endpoint. Call this once after authentication (and again after switching tenants) to obtain everything needed to render the authenticated UI: user profile, role/permission data, active tenant, enabled modules, and a ready-to-render navigation tree.

### Authorization
- **`@authenticated`** — requires the full standard middleware stack (Part 0 §4): `auth:sanctum`, `throttle:tenant_api`, `check.status`, `log.api`, `plan.expiry`, `check.tenant_access`.
- Implemented as an **invokable controller** (`BootstrapController::__invoke`).

### Request

No parameters. Optionally send `X-Tenant-Id: <id>` if you need to bootstrap in the context of a non-default tenant (requires the `access-tenant` gate to pass for that tenant).

### What happens internally

1. Loads the authenticated `User` with `roles` and `permissions` relations eagerly.
2. `TenantManager::getEnabledModules()` — returns the list of module **slugs** enabled for the tenant's active Plan (cached 3600s, keyed `tenant_{id}_modules`).
3. Each module slug is mapped to rich UI metadata from `config('modules.metadata')` (label, route, icon — see table below). Unknown slugs are silently filtered out.
4. `TenantManager::getUserPermissions($user)` — returns the user's effective permission names for the active tenant (cached 3600s).
5. `TenantManager::getActiveTenant()` — the resolved `Tenant` model.

### Response

**`200 OK`**
```json
{
  "user": {
    "id": 12,
    "name": "Jane Owner",
    "email": "jane@acme.com",
    "current_tenant_id": 5,
    "email_verified_at": null,
    "created_at": "2026-01-10T08:00:00.000000Z",
    "updated_at": "2026-01-10T08:00:00.000000Z",
    "roles": [
      { "id": 2, "name": "agency_owner", "guard_name": "web" }
    ],
    "permissions": []
  },
  "permissions": [
    "view leads", "write leads", "update leads", "delete leads",
    "view forms", "write forms", "..."
  ],
  "active_tenant": {
    "id": 5,
    "name": "Acme Marketing Agency",
    "domain": "acme-marketing",
    "status": "active",
    "slug": null,
    "is_active": null,
    "created_at": "2026-01-10T08:00:00.000000Z",
    "updated_at": "2026-01-10T08:00:00.000000Z"
  },
  "enabled_modules": ["leads", "forms", "webhooks", "ai_chats", "ai_agents", "api_keys", "integrations"],
  "module_nav": [
    {
      "slug": "leads",
      "label": "Opportunities",
      "route": "/admin/leads",
      "icon": "M15 19.128a9.38 ... 0 0 1 5.25 0Z"
    },
    {
      "slug": "forms",
      "label": "Forms",
      "route": "/admin/forms",
      "icon": "M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586..."
    },
    {
      "slug": "webhooks",
      "label": "Webhooks",
      "route": "/admin/webhooks",
      "icon": ["M18 16.98h-5.99c...", "m6 17 3.13-5.78c...", "m12 6 3.13 5.73C15.66..."]
    },
    {
      "slug": "ai_chats",
      "label": "AI Chats",
      "route": "/admin/ai-chats",
      "icon": "M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6..."
    },
    {
      "slug": "api_keys",
      "label": "API Keys",
      "route": "/admin/api-keys",
      "icon": ["M12.4 2.7a2.5 2.5 0 0 1 3.4 0l5.5 5.5...", "m14 7 3 3", "m9.4 10.6-6.814..."]
    },
    {
      "slug": "integrations",
      "label": "Integrations",
      "route": "/admin/integrations",
      "icon": ["M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3..."]
    },
    {
      "slug": "ai_agents",
      "label": "Ai Agents",
      "route": "/admin/ai-agents",
      "icon": ["M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3..."]
    }
  ]
}
```

> Note: the `icon` field is either a single SVG path `d` string or an **array** of path `d` strings (multi-path icons, e.g. Lucide-style icons) — render accordingly (one `<path>` per array entry).

### Known module metadata (`config/modules.php`)

| Slug | Label | Route |
|---|---|---|
| `leads` | Opportunities | `/admin/leads` |
| `forms` | Forms | `/admin/forms` |
| `webhooks` | Webhooks | `/admin/webhooks` |
| `ai_chats` | AI Chats | `/admin/ai-chats` |
| `api_keys` | API Keys | `/admin/api-keys` |
| `integrations` | Integrations | `/admin/integrations` |
| `ai_agents` | Ai Agents | `/admin/ai-agents` |

> If `enabled_modules` contains a slug **not** present in this metadata map (e.g. a newly created custom module — see Part 2), it is simply **omitted** from `module_nav` but will still be present in `enabled_modules`. Frontend code should treat `enabled_modules` as the source of truth for feature gating, and `module_nav` purely for rendering the sidebar.

### Possible error responses

| Code | Cause |
|---|---|
| `401` | Missing/invalid bearer token |
| `402` | `subscription_expired` — tenant's plan `expires_at + grace_period_days` has passed (non-super-admins only) |
| `403` | Tenant suspended (`check.status`), or `X-Tenant-Id` access denied (`check.tenant_access`) |
| `429` | Rate limit (`tenant_api` throttle) exceeded |

### cURL

```bash
curl -X GET "{base_url}/api/bootstrap" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"

# With explicit tenant switching:
curl -X GET "{base_url}/api/bootstrap" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "X-Tenant-Id: 7"
```

### Frontend integration notes

- **Call this on every app load and after any tenant switch.** It's the single source of truth for "what can this user see right now."
- Cache invalidation: `enabled_modules` and `permissions` are server-cached for 1 hour (`tenant_{id}_modules`, `user_{id}_tenant_{id}_permissions`). If a Super Admin changes the tenant's plan or a role's permissions, these caches are explicitly busted server-side (`TenantManager::clearTenantCache`) — you do **not** need to manually bust anything client-side, but you should re-call `/bootstrap` after actions that might change your own access (e.g. after `assign-plan`, role change).
- `permissions` is a flat array of permission **names** (Spatie format, e.g. `"view leads"`), distinct from Sanctum token **abilities/scopes** (e.g. `"leads:view"` used in Part 8). Both layers are enforced — see Part 0 §5 and each module's Policy notes.

---

## 5. `POST /n8n/token` (Reserved — currently disabled)

This route is **commented out** in `routes/api.php` but the controller method `AuthController::n8nToken()` exists and is fully implemented. It is documented here for completeness in case it's re-enabled in your deployment, or you want to register it yourself.

### Intended Authorization
- `@authenticated` (standard stack)

### Request
| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | ❌ | Token name. Defaults to `"n8n-webhook"`. `max:255` |

### Behavior

Creates a new Sanctum token scoped to the single ability `n8n:webhook` (note: this scope is **not** currently present in `config('sanctum.allowed_scopes')` — see Part 8 — so if re-enabling this route, also add `'n8n:webhook'` to the allowed scopes list and define corresponding policy checks).

### Response

```json
{
  "token": "3|n8nScopedPlainTextToken..."
}
```

### cURL (if enabled)

```bash
curl -X POST "{base_url}/api/n8n/token" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name": "n8n-leads-webhook"}'
```

---

## 6. End-to-End Flow Summary

```
┌──────────┐  POST /register or /login   ┌─────────────────────┐
│  Client  ├─────────────────────────────►│ Sanctum Bearer Token │
└────┬─────┘                              └──────────┬──────────┘
     │                                                │
     │ Authorization: Bearer <token>                  │
     ▼                                                ▼
GET /bootstrap  ──────────────────────────►  { user, permissions,
                                                 active_tenant,
                                                 enabled_modules,
                                                 module_nav }
     │
     ▼
Render sidebar from module_nav, gate UI actions using `permissions`
and `enabled_modules`. For each subsequent API call, attach the
same Bearer token (+ X-Tenant-Id if operating cross-tenant).
```

---

**Next:** Part 2 — Tenants, Plans & Modules (Super Admin Management).

> Reply "continue" and I'll proceed to **Part 2: Tenant, Plan & Module Management**.
