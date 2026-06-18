# Agency SaaS Platform — Developer API Reference
## Part 2: Tenant, Plan & Module Management (Super Admin)

> **Service:** `business-agency-saas-api` (Laravel)
> **Controllers:** `App\Http\Controllers\TenantController`, `App\Http\Controllers\Admin\PlanController`
> **Base path:** `{base_url}/api`
> See **Part 0** for global conventions and **Part 1** for authentication.

---

## 1. Module Overview

This module is the **platform operator's control panel**. It is used to:

- Manage **Tenants** (agencies) — create, update, suspend, delete.
- Manage **Plans** (subscription tiers) and **Modules** (feature flags).
- Assign a Plan to a Tenant (with expiry).
- Configure a tenant's **CRM pipeline** (custom statuses/entity naming for the Leads module — also exposed to tenant-level users).
- Inspect which modules a tenant currently has access to.

### Authorization model

Almost every endpoint in this module performs a **manual super-admin check** at the top of the controller method (not via Laravel Policies):

```php
if (! $user || ! $user->isSuperAdmin()) {
    return response()->json(['message' => 'Forbidden'], 403);
}
```

`isSuperAdmin()` checks the Spatie role `super_admin`. **Super admins bypass tenant/module/plan-expiry middleware entirely** (see Part 0 §4–5).

All endpoints additionally sit behind the standard authenticated middleware stack (`auth:sanctum`, `throttle:tenant_api`, `check.status`, `log.api`, `plan.expiry`, `check.tenant_access`) — but since super admins bypass `plan.expiry` and `check.tenant_access` effectively has nothing to deny for them, in practice the **manual `isSuperAdmin()` check is the real gate**.

> ⚠️ **Two exceptions** are NOT super-admin gated:
> - `GET /tenants/modules` — any authenticated tenant user can call this to see **their own tenant's** enabled modules (with full module objects, not just slugs).
> - `POST /tenants/crm-config` — any authenticated tenant user (with appropriate Lead policy access) can update **their own tenant's** CRM pipeline configuration.

---

## 2. Data Model Reference

### `Tenant`
| Field | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `name` | string | |
| `domain` | string\|null | unique |
| `status` | enum | `active` \| `suspended` |
| `slug` | string\|null | |
| `is_active` | bool\|null | |

Relations: `plans()` (belongsToMany `Plan` via `plan_tenant`, pivot: `expires_at`, `grace_period_days`, `ai_credits_used`), `users()` (belongsToMany `User` via `tenant_user`), `settings()` (hasOne `TenantSetting`).

### `Plan`
| Field | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `name` | string | |
| `slug` | string | unique |
| `price` | numeric | |
| `ai_credit_limit` | int | `-1` = unlimited, `0` = disabled, `>0` = credit cap |

Relations: `modules()` (belongsToMany `Module` via `module_plan`, pivot: `limit`), `tenants()` (belongsToMany `Tenant` via `plan_tenant`).

### `Module`
| Field | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `name` | string | Human label |
| `slug` | string | unique, used in `check.module:<slug>` middleware and `enabled_modules` |

### `module_plan` pivot
| Field | Notes |
|---|---|
| `limit` | `-1` = unlimited, `0` = module disabled for this plan, `N` = hard cap on resource count (enforced via `TenantManager::checkLimit`) |

### `TenantSetting`
| Field | Type | Notes |
|---|---|---|
| `tenant_id` | int | FK, unique (hasOne) |
| `crm_config` | json/array | See §6 |
| `ai_provider_default` | string | `openai` \| `gemini` (used by Part 7) |

---

## 3. `GET /tenants`

List all tenants with their plans and CRM settings, plus the full catalog of available plans (with modules) for use in admin UI dropdowns.

### Authorization
- `@authenticated` + **Super Admin only**.

### Request
No parameters.

### Response

**`200 OK`**
```json
{
  "tenants": [
    {
      "id": 5,
      "name": "Acme Marketing Agency",
      "domain": "acme-marketing",
      "status": "active",
      "slug": null,
      "is_active": null,
      "created_at": "2026-01-10T08:00:00.000000Z",
      "updated_at": "2026-02-01T12:00:00.000000Z",
      "plans": [
        {
          "id": 2,
          "name": "Pro",
          "slug": "pro",
          "price": "99.00",
          "ai_credit_limit": 5000,
          "pivot": {
            "tenant_id": 5,
            "plan_id": 2,
            "expires_at": "2026-12-31T00:00:00.000000Z",
            "grace_period_days": 3,
            "ai_credits_used": 120,
            "created_at": "2026-01-10T08:00:00.000000Z",
            "updated_at": "2026-01-10T08:00:00.000000Z"
          },
          "modules": [
            { "id": 1, "name": "Leads Management", "slug": "leads", "pivot": { "plan_id": 2, "module_id": 1, "limit": -1 } }
          ]
        }
      ],
      "settings": {
        "id": 5,
        "tenant_id": 5,
        "crm_config": {
          "entity_name_singular": "Lead",
          "entity_name_plural": "Leads",
          "statuses": [
            { "slug": "new", "label": "New", "color": "blue" },
            { "slug": "contacted", "label": "Contacted", "color": "yellow" },
            { "slug": "closed", "label": "Closed", "color": "green" }
          ]
        }
      }
    }
  ],
  "available_plans": [
    {
      "id": 1,
      "name": "Starter",
      "slug": "starter",
      "price": "0.00",
      "ai_credit_limit": 100,
      "modules": [
        { "id": 1, "name": "Leads Management", "slug": "leads", "pivot": { "plan_id": 1, "module_id": 1, "limit": 50 } }
      ]
    },
    {
      "id": 2,
      "name": "Pro",
      "slug": "pro",
      "price": "99.00",
      "ai_credit_limit": 5000,
      "modules": [ "..." ]
    }
  ]
}
```

**`403 Forbidden`** (non-super-admin)
```json
{ "message": "Forbidden" }
```

### cURL

```bash
curl -X GET "{base_url}/api/tenants" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Accept: application/json"
```

### Notes
- `settings:id,tenant_id,crm_config` is eager-loaded with a column projection — only those 3 columns are returned for `settings`.
- If a tenant has no `TenantSetting` row yet, `settings` will be `null`.
- Tenants are ordered by `id DESC` (newest first).

---

## 4. `POST /tenants`

Create a new tenant, optionally assigning a Plan and/or initial CRM configuration in the same call.

### Authorization
- `@authenticated` + **Super Admin only**.

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `name` | string | ✅ | `max:255` |
| `domain` | string | ❌ | `max:255`, unique in `tenants.domain` |
| `status` | string | ❌ | `in:active,suspended` (default DB value applies if omitted) |
| `plan_id` | int | ❌ | `exists:plans,id` |
| `expires_at` | date | ❌ | nullable; ISO date — sets `plan_tenant.expires_at` |
| `crm_config` | object | ❌ | see structure below |
| `crm_config.entity_name_singular` | string | conditional | required **if** `crm_config` present |
| `crm_config.entity_name_plural` | string | conditional | required **if** `crm_config` present |
| `crm_config.statuses` | array | conditional | required, `min:1` |
| `crm_config.statuses[].slug` | string | conditional | required, **distinct** within the array |
| `crm_config.statuses[].label` | string | conditional | required |
| `crm_config.statuses[].color` | string | conditional | required |

### What happens internally

1. Creates the `Tenant` row (`status` defaults to `'active'` if not provided).
2. If `plan_id` provided: `tenant->plans()->sync([$plan_id => ['expires_at' => $expires_at ?? null]])` — **note**: `sync()` here means a tenant can only have **one active plan at a time** by design (any prior plans are detached).
3. If `crm_config` provided: creates a `TenantSetting` row.
4. Returns the tenant with `plans.modules` and `settings` eager-loaded.

### Response

**`201 Created`**
```json
{
  "id": 9,
  "name": "Beta Agency",
  "domain": "beta-agency",
  "status": "active",
  "slug": null,
  "is_active": null,
  "created_at": "2026-03-01T10:00:00.000000Z",
  "updated_at": "2026-03-01T10:00:00.000000Z",
  "plans": [
    {
      "id": 1,
      "name": "Starter",
      "slug": "starter",
      "price": "0.00",
      "ai_credit_limit": 100,
      "pivot": { "tenant_id": 9, "plan_id": 1, "expires_at": "2026-04-01T00:00:00.000000Z", "grace_period_days": null, "ai_credits_used": 0 },
      "modules": [ "..." ]
    }
  ],
  "settings": {
    "id": 9,
    "tenant_id": 9,
    "crm_config": {
      "entity_name_singular": "Patient",
      "entity_name_plural": "Patients",
      "statuses": [
        { "slug": "new", "label": "New", "color": "blue" },
        { "slug": "scheduled", "label": "Scheduled", "color": "purple" },
        { "slug": "treated", "label": "Treated", "color": "green" }
      ]
    }
  }
}
```

**`422 Unprocessable Entity`**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "domain": ["The domain has already been taken."],
    "crm_config.statuses.0.slug": ["The crm_config.statuses.0.slug field is required when crm config is present."]
  }
}
```

### cURL

```bash
curl -X POST "{base_url}/api/tenants" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Beta Agency",
    "domain": "beta-agency",
    "status": "active",
    "plan_id": 1,
    "expires_at": "2026-04-01",
    "crm_config": {
      "entity_name_singular": "Patient",
      "entity_name_plural": "Patients",
      "statuses": [
        {"slug": "new", "label": "New", "color": "blue"},
        {"slug": "scheduled", "label": "Scheduled", "color": "purple"},
        {"slug": "treated", "label": "Treated", "color": "green"}
      ]
    }
  }'
```

---

## 5. `PATCH /tenants/{tenant}`

Update an existing tenant's profile, plan assignment, and/or CRM config.

### Authorization
- `@authenticated` + **Super Admin only**.

### URL Parameters
| Param | Type | Description |
|---|---|---|
| `tenant` | int | Tenant ID (route-model-bound) |

### Request

`Content-Type: application/json` — all fields **optional** (`sometimes` validation):

| Field | Type | Validation |
|---|---|---|
| `name` | string | `sometimes\|string\|max:255` |
| `domain` | string\|null | `sometimes\|nullable\|max:255`, unique ignoring current tenant |
| `status` | string | `sometimes\|in:active,suspended` |
| `plan_id` | int | `sometimes\|exists:plans,id` |
| `expires_at` | date\|null | `nullable` |
| `crm_config` | object | same shape/validation as §4 |

### What happens internally

1. `$tenant->update($validated)` — updates basic fields directly present in the payload.
2. If `plan_id` present:
   - `tenant->plans()->sync([$plan_id => ['expires_at' => ...]])` — **replaces** the tenant's plan assignment (single active plan model).
   - **Cache busting**: `TenantManager::clearTenantCache($tenant->id)` is called — clears `tenant_{id}_modules`, every affected user's `user_{uid}_tenant_{id}_permissions`, and `tenant_{id}_ai_limit`. This is what makes plan changes take effect **immediately** without waiting for cache TTL.
3. If `crm_config` present: `TenantSetting::updateOrCreate(['tenant_id' => $tenant->id], ['crm_config' => $validated['crm_config']])`.
4. Returns the tenant with `plans.modules` and `settings` reloaded.

### Response

**`200 OK`** — same shape as the `POST /tenants` response (single tenant object).

**`403 Forbidden`** — non-super-admin.

**`422 Unprocessable Entity`** — validation errors (same shape as §4).

### cURL

```bash
# Suspend a tenant
curl -X PATCH "{base_url}/api/tenants/9" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"status": "suspended"}'

# Change plan + extend expiry
curl -X PATCH "{base_url}/api/tenants/9" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"plan_id": 2, "expires_at": "2027-01-01"}'
```

### Important behavioral notes

- **Suspension is enforced at two layers**:
  1. `AuthController::login` blocks login outright with `403` if `currentTenant.status === 'suspended'`.
  2. `CheckTenantStatus` middleware (`check.status`) blocks **already-authenticated** sessions on every subsequent request with `403 {"message": "Your tenant account temporarily deactivated."}`.
  - Net effect: suspending a tenant mid-session immediately blocks all further API calls for users of that tenant on their next request.
- Changing `plan_id` triggers immediate cache invalidation — frontend should re-call `GET /bootstrap` for affected users to refresh `enabled_modules`.

---

## 6. `DELETE /tenants/{tenant}`

Permanently delete a tenant — **only if it has no users attached**.

### Authorization
- `@authenticated` + **Super Admin only**.

### Response

**`204 No Content`** — success.

**`409 Conflict`** — tenant still has users:
```json
{
  "message": "Cannot delete this tenant because it is currently assigned to clients. Please reassign them to a different tenant first or delete them entirely."
}
```

**`403 Forbidden`** — non-super-admin.

### cURL

```bash
curl -X DELETE "{base_url}/api/tenants/9" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Accept: application/json"
```

> ⚠️ There is no cascade-detach option exposed via API — you must first reassign or delete each user attached to the tenant (via `tenant_user`) before the tenant itself can be deleted.

---

## 7. `GET /tenants/modules`

Get the list of **Module objects** (full records, including pivot `limit`) enabled for the **current authenticated user's active tenant**. Unlike `bootstrap`'s `enabled_modules` (slugs only), this returns full module rows with limits.

### Authorization
- `@authenticated` (standard stack). **Available to any tenant user**, not just super admins.

### Response

**`200 OK`**
```json
[
  { "id": 1, "name": "Leads Management", "slug": "leads", "created_at": "...", "updated_at": "...", "pivot": { "plan_id": 2, "module_id": 1, "limit": -1 } },
  { "id": 2, "name": "Forms", "slug": "forms", "pivot": { "plan_id": 2, "module_id": 2, "limit": 10 } },
  { "id": 3, "name": "Webhooks", "slug": "webhooks", "pivot": { "plan_id": 2, "module_id": 3, "limit": -1 } }
]
```

**`400 Bad Request`** — no active tenant context:
```json
{ "message": "No active tenant context." }
```

If the tenant has no assigned plan at all: `{"modules": []}` (note the different envelope — an object with `modules` key — in this specific edge case vs. a bare array on success).

### cURL

```bash
curl -X GET "{base_url}/api/tenants/modules" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

### Notes
- ⚠️ **Single active plan assumption**: this endpoint assumes `tenant->plans->first()` — i.e. exactly one plan per tenant. If multiple plans were ever attached (shouldn't happen given `sync()` usage elsewhere), only the first is considered.
- Use the `pivot.limit` value to render usage meters (e.g. "23 / 50 leads used this month") — combine with the relevant module's count endpoint (e.g. `GET /leads/stats`).

---

## 8. `POST /tenants/crm-config`

Update the **current tenant's** CRM pipeline configuration (entity naming + status pipeline). This powers the dynamic Leads module UI (Part 3) — agencies in different industries (real estate, medical, legal, etc.) can rename "Leads" to "Patients"/"Cases"/"Deals" and define their own status pipeline.

### Authorization
- `@authenticated` (standard stack). Any tenant user can call this — no explicit `isSuperAdmin()` check (operates on `request()->user()->current_tenant_id`).

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `entity_name_singular` | string | ✅ | `max:50` |
| `entity_name_plural` | string | ✅ | `max:50` |
| `statuses` | array | ✅ | `min:1` |
| `statuses[].slug` | string | ✅ | **distinct** within array |
| `statuses[].label` | string | ✅ | |
| `statuses[].color` | string | ✅ | free-form (e.g. Tailwind color name) |

### What happens internally

`TenantSetting::firstOrCreate(['tenant_id' => current_tenant_id])`, then overwrites `crm_config` and saves.

### Response

**`200 OK`** — returns the saved `crm_config` object directly (not wrapped):
```json
{
  "entity_name_singular": "Deal",
  "entity_name_plural": "Deals",
  "statuses": [
    { "slug": "new", "label": "New", "color": "blue" },
    { "slug": "negotiation", "label": "Negotiation", "color": "orange" },
    { "slug": "won", "label": "Won", "color": "green" },
    { "slug": "lost", "label": "Lost", "color": "red" }
  ]
}
```

### cURL

```bash
curl -X POST "{base_url}/api/tenants/crm-config" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "entity_name_singular": "Deal",
    "entity_name_plural": "Deals",
    "statuses": [
      {"slug": "new", "label": "New", "color": "blue"},
      {"slug": "negotiation", "label": "Negotiation", "color": "orange"},
      {"slug": "won", "label": "Won", "color": "green"},
      {"slug": "lost", "label": "Lost", "color": "red"}
    ]
  }'
```

### Frontend integration notes

- This `crm_config` is also returned alongside every Lead response (`GET /leads/stats`, `GET /leads/{id}`) via `LeadController::getCrmConfig()` — so the Leads UI always reflects the latest pipeline without a separate fetch.
- If `TenantSetting` doesn't exist yet, `LeadController` falls back to a **default** config:
  ```json
  {
    "entity_name_singular": "Lead",
    "entity_name_plural": "Leads",
    "statuses": [
      {"slug": "new", "label": "New", "color": "blue"},
      {"slug": "contacted", "label": "Contacted", "color": "yellow"},
      {"slug": "closed", "label": "Closed", "color": "green"}
    ]
  }
  ```
- `statuses[].slug` values are what gets stored in `leads.status` — choose stable slugs since lead update/filter endpoints reference them directly (Part 3).

---

## 9. `GET /plans-data` (Admin — Plans & Modules catalog)

`App\Http\Controllers\Admin\PlanController::index`

### Authorization
- `@authenticated` + **Super Admin only**.

### Response

**`200 OK`**
```json
{
  "plans": [
    {
      "id": 1,
      "name": "Starter",
      "slug": "starter",
      "price": "0.00",
      "ai_credit_limit": 100,
      "modules": [
        { "id": 1, "name": "Leads Management", "slug": "leads", "pivot": { "plan_id": 1, "module_id": 1, "limit": 50 } },
        { "id": 2, "name": "Forms", "slug": "forms", "pivot": { "plan_id": 1, "module_id": 2, "limit": 1 } }
      ]
    },
    {
      "id": 2,
      "name": "Pro",
      "slug": "pro",
      "price": "99.00",
      "ai_credit_limit": 5000,
      "modules": [ "..." ]
    }
  ],
  "modules": [
    { "id": 1, "name": "Leads Management", "slug": "leads", "created_at": "...", "updated_at": "..." },
    { "id": 2, "name": "Forms", "slug": "forms" },
    { "id": 3, "name": "Webhooks", "slug": "webhooks" },
    { "id": 4, "name": "AI Chats", "slug": "ai_chats" },
    { "id": 5, "name": "API Keys", "slug": "api_keys" },
    { "id": 6, "name": "Integrations", "slug": "integrations" },
    { "id": 7, "name": "Ai Agents", "slug": "ai_agents" }
  ]
}
```

### cURL

```bash
curl -X GET "{base_url}/api/plans-data" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Accept: application/json"
```

---

## 10. `POST /plans` — Create a Plan

`PlanController::storePlan`

### Authorization
- `@authenticated` + **Super Admin only**.

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `name` | string | ✅ | |
| `slug` | string | ✅ | unique in `plans.slug` |
| `price` | numeric | ✅ (validated as `numeric`, but no `required` rule — send `0` if free) | |
| `modules` | array | ❌ | array of `{id: <module_id>, limit: <int>}` |

> ⚠️ Note: `ai_credit_limit` is **not** set by `storePlan` (only by `updatePlan`, §11). If you need to set AI credits on creation, create the plan then immediately `PUT /plans/{id}`.

### What happens internally

1. `Plan::create($validated)` — only `name`, `slug`, `price` are mass-assignable per `$fillable = ['name', 'slug']` in the model... **however** the controller passes the full `$validated` array to `create()`. Since `price` is not in `Plan::$fillable`, **`price` will be silently dropped unless the model's `$fillable` is extended** — flag this for backend review if `price` doesn't persist as expected.
2. `$plan->modules()->sync($pivotData)` where `$pivotData = [module_id => ['limit' => N], ...]` built from the `modules` array.

### Response

**`200 OK`** — returns the created `Plan` (without modules eager-loaded in this specific response, unlike `updatePlan`):
```json
{
  "id": 3,
  "name": "Enterprise",
  "slug": "enterprise",
  "price": "299.00",
  "ai_credit_limit": null,
  "created_at": "2026-03-01T10:00:00.000000Z",
  "updated_at": "2026-03-01T10:00:00.000000Z"
}
```

### cURL

```bash
curl -X POST "{base_url}/api/plans" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Enterprise",
    "slug": "enterprise",
    "price": 299,
    "modules": [
      {"id": 1, "limit": -1},
      {"id": 2, "limit": -1},
      {"id": 3, "limit": -1},
      {"id": 4, "limit": -1},
      {"id": 5, "limit": -1},
      {"id": 6, "limit": -1},
      {"id": 7, "limit": -1}
    ]
  }'
```

---

## 11. `PUT /plans/{plan}` — Update a Plan

`PlanController::updatePlan`

### Authorization
- `@authenticated` + **Super Admin only**.

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `name` | string | ✅ | |
| `slug` | string | ✅ | unique in `plans.slug`, **ignoring current plan id** |
| `price` | numeric | ✅ | |
| `ai_credit_limit` | int | ❌ | `-1` = unlimited, `0` = AI disabled, `N` = credit cap. Read directly from `$request->ai_credit_limit` (not part of the `validate()` rules — passed through as-is) |
| `modules` | array | ❌ | array of `{id, limit}` — if present, **replaces** the entire module set via `sync()` |

### What happens internally

1. Updates `name`, `slug`, `price`, `ai_credit_limit` on the plan.
2. If `modules` present, re-syncs `plan->modules()` pivot table with new `limit` values.
3. **Cache busting cascade**: iterates **every tenant** subscribed to this plan (`$plan->tenants()->chunk(50, ...)`) and calls `TenantManager::clearTenantCache($tenant->id)` for each — ensuring all subscribers see new module/limit changes immediately, without waiting for the 3600s cache TTL.

### Response

**`200 OK`** — returns the plan with `modules` eager-loaded:
```json
{
  "id": 2,
  "name": "Pro",
  "slug": "pro",
  "price": "129.00",
  "ai_credit_limit": 10000,
  "modules": [
    { "id": 1, "name": "Leads Management", "slug": "leads", "pivot": { "plan_id": 2, "module_id": 1, "limit": -1 } },
    { "id": 4, "name": "AI Chats", "slug": "ai_chats", "pivot": { "plan_id": 2, "module_id": 4, "limit": -1 } }
  ]
}
```

### cURL

```bash
curl -X PUT "{base_url}/api/plans/2" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "name": "Pro",
    "slug": "pro",
    "price": 129,
    "ai_credit_limit": 10000,
    "modules": [
      {"id": 1, "limit": -1},
      {"id": 4, "limit": -1}
    ]
  }'
```

### ⚠️ Important: bulk re-sync semantics
`modules` is a **full replacement**, not a patch. Omitting a module from the array on update will **remove** that module from the plan (and therefore from every subscribed tenant, instantly, via the cache-busting cascade). Always send the **complete desired module set**.

---

## 12. `DELETE /plans/{plan}` — Delete a Plan

`PlanController::destroyPlan`

### Authorization
- `@authenticated` + **Super Admin only**.

### Response

**`200 OK`**
```json
{ "message": "Plan deleted successfully." }
```

**`409 Conflict`** — plan still assigned to tenants:
```json
{ "message": "Cannot delete this plan because it is currently assigned to tenants. Please reassign them to a different plan first." }
```

### cURL

```bash
curl -X DELETE "{base_url}/api/plans/3" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Accept: application/json"
```

> Deleting a plan cascades to remove its `module_plan` rows (DB-level `ON DELETE CASCADE`), but the controller blocks deletion entirely while `plan->tenants()->exists()` — so no tenant is ever left without a plan due to a plan deletion.

---

## 13. `POST /modules` — Create a Module (Feature Flag)

`PlanController::storeModule`

### Authorization
- `@authenticated` + **Super Admin only**.

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `name` | string | ✅ | Human-readable label, e.g. `"Knowledge Base"` |
| `slug` | string | ✅ | unique in `modules.slug`, e.g. `"knowledge_base"` |

### Response

**`200 OK`**
```json
{
  "id": 8,
  "name": "Knowledge Base",
  "slug": "knowledge_base",
  "created_at": "2026-03-01T10:00:00.000000Z",
  "updated_at": "2026-03-01T10:00:00.000000Z"
}
```

### cURL

```bash
curl -X POST "{base_url}/api/modules" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name": "Knowledge Base", "slug": "knowledge_base"}'
```

### ⚠️ Developer notes for new modules

Creating a module via this endpoint **only** registers it in the `modules` table — it does **not**:
1. Add it to any Plan (you must `PUT /plans/{id}` with the updated `modules` array to attach it).
2. Add UI metadata to `config('modules.metadata')` — without this, `GET /bootstrap`'s `module_nav` will **not** show a sidebar entry for it (though `enabled_modules` will include the slug). This config requires a code deploy.
3. Wire up any `check.module:<slug>` middleware on new routes — you must add the relevant route group yourself.

---

## 14. `POST /tenants/{tenant}/assign-plan`

`PlanController::assignPlan` — a focused alternative to `PATCH /tenants/{tenant}` for the single common operation of (re)assigning a plan.

### Authorization
- `@authenticated` + **Super Admin only**.

### URL Parameters
| Param | Type |
|---|---|
| `tenant` | int (route-model-bound) |

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `plan_id` | int | ✅ | `exists:plans,id` |
| `expires_at` | date\|null | ❌ | nullable |

### What happens internally

Identical pivot-sync + cache-bust logic as in `PATCH /tenants/{tenant}` (§5), isolated into a single-purpose endpoint:

```php
$tenant->plans()->sync([$validated['plan_id'] => ['expires_at' => $validated['expires_at'] ?? null]]);
$this->tenantManager->clearTenantCache($tenant->id);
```

### Response

**`200 OK`**
```json
{ "message": "Plan assigned and cache updated." }
```

### cURL

```bash
curl -X POST "{base_url}/api/tenants/9/assign-plan" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"plan_id": 2, "expires_at": "2027-01-01"}'
```

### Recommended onboarding sequence (new tenant)

```bash
# 1. Create tenant (no plan yet)
TENANT_ID=$(curl -s -X POST "{base_url}/api/tenants" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"name":"New Agency","status":"active"}' | jq -r '.id')

# 2. Assign a plan with a 14-day trial expiry
curl -X POST "{base_url}/api/tenants/$TENANT_ID/assign-plan" \
  -H "Authorization: Bearer $SUPER_ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d "{\"plan_id\": 1, \"expires_at\": \"$(date -u -d '+14 days' +%Y-%m-%d)\"}"

# 3. (Optional) Set CRM config as the tenant owner
curl -X POST "{base_url}/api/tenants/crm-config" \
  -H "Authorization: Bearer $TENANT_OWNER_TOKEN" -H "Content-Type: application/json" \
  -d '{"entity_name_singular":"Lead","entity_name_plural":"Leads","statuses":[{"slug":"new","label":"New","color":"blue"}]}'
```

---

## 15. Plan Expiry & Grace Period — Behavioral Reference

This module configures the data (`plan_tenant.expires_at`, `grace_period_days`) that the `CheckPlanExpiry` middleware enforces on **every** authenticated, non-super-admin request:

```
now() > expires_at + grace_period_days  →  402 Payment Required
{
  "error": "subscription_expired",
  "message": "Your access has been suspended on 12 Jan, 2026. Please renew your subscription to regain access."
}
```

| Scenario | Result |
|---|---|
| `expires_at = null` | Plan never expires — no `402` ever triggered for this tenant |
| `expires_at` in future | Full access |
| `expires_at` in past, within `grace_period_days` | Full access (grace period) |
| `expires_at + grace_period_days` in past | All non-bootstrap-exempt* requests return `402` |
| `tenant->plans->first()` is `null` (no plan at all) | `403 {"message": "No active plan found for this tenant."}` |
| Requesting user `isSuperAdmin()` | Always passes, regardless of plan state |

\* *Every route in the standard authenticated group is subject to this, including `/bootstrap` — so a SPA should handle `402` globally (e.g. redirect to a "renew subscription" screen) rather than per-endpoint.*

`grace_period_days` defaults to **3** if not set on the pivot. There is currently **no documented endpoint to set `grace_period_days`** directly — it must be set via direct DB access/migration default, or you can extend `assign-plan`/`PATCH /tenants/{id}` validation to accept it (not currently in the validated field list).

---

**Next:** Part 3 — Leads (CRM) Module — the largest and most feature-rich module (stats, CRUD, batch insert, CSV import/export, activities).

> Reply "continue" and I'll proceed to **Part 3: Leads (CRM) Module**.
