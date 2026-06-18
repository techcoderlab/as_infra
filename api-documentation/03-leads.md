# Agency SaaS Platform — Developer API Reference
## Part 3: Leads (CRM) Module

> **Service:** `business-agency-saas-api` (Laravel)
> **Controller:** `App\Http\Controllers\LeadController`
> **Model:** `App\Models\Lead` (+ `LeadActivity`, `Form`, `AiJob`)
> **Policy:** `App\Policies\LeadPolicy`
> **Base path:** `{base_url}/api`
> **Requires module:** `leads` (`check.module:leads` — all routes in this section return `403` if the tenant's plan doesn't include the `leads` module)
> See **Part 0** for global conventions and **Part 2 §8** for the CRM pipeline configuration (`crm_config`) returned by several endpoints here.

---

## 1. Module Overview

The Leads module is an **industry-agnostic CRM**. Instead of fixed columns per industry, leads store a flexible JSONB `payload` (dynamic form fields) alongside a small set of universal CRM fields (`status`, `temperature`, `score`, `source`, `won`). The tenant's `crm_config` (Part 2 §8) defines the **status pipeline** and **entity naming** ("Lead" vs "Patient" vs "Deal") used to render this generic data in industry-specific UIs.

### Endpoints in this module

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/leads/stats` | Dashboard metrics: totals, growth, trend chart, top sources, CRM config |
| `GET` | `/leads` | Paginated, filterable, searchable list |
| `GET` | `/leads/{lead}` | Single lead detail + activities + latest AI job + CRM config |
| `GET` | `/leads/{lead}/activities` | Paginated activity/audit log for one lead |
| `POST` | `/leads/{lead}/note` | Add a manual/system note |
| `POST` | `/leads` | Create a single lead |
| `POST` | `/leads/batch` | Bulk-insert leads (JSON array) |
| `PUT` | `/leads/{lead}` | Update status/temperature/notes |
| `POST` | `/leads/import` | Bulk-insert leads from CSV upload |
| `POST` | `/leads/export` | Stream a CSV export |

### Authorization model (`LeadPolicy::checkAccess`)

Every action runs through a 4-layer check:

1. **Super admin bypass** — `isSuperAdmin()` → always allowed.
2. **Tenant context required** — `current_tenant_id` must be set.
3. **Module check** — `leads` module must be enabled for the tenant's plan (`TenantManager::isModuleEnabled('leads')`).
4. **Permission + Scope check** — `$user->can('<permission>')` (Spatie permission, e.g. `"view leads"`) **AND** `$user->tokenCan('<scope>')` (Sanctum ability, e.g. `"leads:view"`).

| Action | Permission | Sanctum Scope |
|---|---|---|
| `viewAny` (list, stats) | `view leads` | `leads:view` |
| `view` (show, activities) | `view leads` | `leads:view` |
| `create` (store, batch, import) | `write leads` | `leads:write` (+ plan **limit check**, see below) |
| `update` (update, addNote) | `update leads` | `leads:update` |
| `delete` | `delete leads` | `leads:delete` (not currently exposed via a route) |

> **Plan limit enforcement on create**: `LeadPolicy::create()` additionally calls `TenantManager::checkLimit('leads', Lead::class)`. If the tenant's plan defines a module limit `N` (`module_plan.limit`) and the tenant already has `>= N` leads, creation is **denied (`403`)** — applies to `POST /leads`, `POST /leads/batch`, and `POST /leads/import` alike. `-1` = unlimited.

### Multi-tenancy

`Lead` uses the `BelongsToTenant` global scope — every query automatically filters to `tenant_id = current_tenant_id`. You never pass `tenant_id` in request bodies; it's injected server-side.

---

## 2. Data Model: `Lead`

| Field | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `tenant_id` | int | auto-injected |
| `form_id` | uuid\|null | FK → `forms.id`, set if created via a Form submission |
| `source` | string | e.g. `"Facebook Ads"`, `"whatsapp"`, `"google_review"`, `"undefined"` |
| `insert_method` | string | `single` \| `bulk` \| `csv` |
| `temperature` | enum | `cold` \| `warm` \| `hot` |
| `status` | string | tenant-defined slug (see `crm_config.statuses`), e.g. `new`, `contacted`, `closed` |
| `score` | numeric\|null | lead score (settable internally / via MCP) |
| `won` | bool\|null | win/loss flag |
| `payload` | json (array) | **dynamic** key-value form data — this is where 90% of "the data" lives |
| `meta_data` | json (array)\|null | technical metadata (IP, user agent, referrer, bot signals, etc.) |
| `notes` | string\|null | |
| `created_at` / `updated_at` | timestamps | |

### Relations
- `form()` → `Form` (the originating form, if any)
- `activities()` → `LeadActivity[]`, ordered newest-first
- `jobs()` → `AiJob[]` (polymorphic, `morphMany`)
- `latestJob()` → `AiJob` (polymorphic, `morphOne`, latest by `started_at`)

### `LeadActivity`
| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `lead_id` | int | |
| `type` | string | e.g. `system_inserted`, `external_system_inserted`, `system_added_note`, `system_updated_status`, `mcp_updated`, `message_received`, `review_replied`, `system_error` |
| `content` | string | human-readable description |
| `metadata` | json\|null | |
| `created_at` | timestamp | |

---

## 3. `GET /leads/stats`

Dashboard endpoint: returns aggregate KPIs, a 7-day trend chart, top lead sources, available filter values, **and** the tenant's `crm_config`. Cached **120 seconds** per tenant (`dashboard_stats_{tenant_id}`).

### Authorization
- `@authenticated` + `check.module:leads` + `LeadPolicy::viewAny` (`view leads` permission, `leads:view` scope).

### Request
No parameters.

### Response

**`200 OK`**
```json
{
  "stats": {
    "overview": {
      "total_leads": 482,
      "new_leads": 37,
      "hot_leads": 12,
      "conversion_rate": 18.5,
      "stale_leads": 9
    },
    "growth": {
      "this_month": 65,
      "last_month": 52,
      "percentage": 25.0
    },
    "chart_data": {
      "2026-06-09": 4,
      "2026-06-10": 7,
      "2026-06-11": 2,
      "2026-06-12": 9,
      "2026-06-13": 5,
      "2026-06-14": 3,
      "2026-06-15": 6
    },
    "top_sources": [
      { "source": "Facebook Ads", "count": 180 },
      { "source": "Google Ads", "count": 120 },
      { "source": "whatsapp", "count": 95 },
      { "source": "Website Form", "count": 60 }
    ],
    "leads_search_filters": {
      "temperatures": ["cold", "warm", "hot"],
      "sources": ["Facebook Ads", "Google Ads", "whatsapp", "Website Form"]
    }
  },
  "config": {
    "entity_name_singular": "Lead",
    "entity_name_plural": "Leads",
    "statuses": [
      { "slug": "new", "label": "New", "color": "blue" },
      { "slug": "contacted", "label": "Contacted", "color": "yellow" },
      { "slug": "closed", "label": "Closed", "color": "green" }
    ]
  }
}
```

### Field semantics

| Field | Definition |
|---|---|
| `overview.total_leads` | `COUNT(*)` for tenant |
| `overview.new_leads` | `COUNT(*) WHERE status = 'new'` |
| `overview.hot_leads` | `COUNT(*) WHERE temperature = 'hot'` |
| `overview.conversion_rate` | `(closed_leads / total) * 100`, rounded to 1 decimal, where `closed_leads = COUNT(*) WHERE status='closed' AND won=true` |
| `overview.stale_leads` | `COUNT(*) WHERE status='new' AND created_at < now() - 1 day` — "needs attention" |
| `growth.this_month` / `last_month` | leads created in current vs. previous calendar month |
| `growth.percentage` | `((this_month - last_month) / last_month) * 100`; if `last_month == 0` and `this_month > 0` → `100`; if both `0` → `0` |
| `chart_data` | 7-day map `{date: count}`, **zero-filled** for days with no leads — ready for Chart.js/ApexCharts |
| `top_sources` | top 4 sources by count, descending |
| `leads_search_filters.sources` | **all** sources (not just top 4) — for populating a filter dropdown |

### cURL

```bash
curl -X GET "{base_url}/api/leads/stats" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

### Performance/caching notes
- Postgres-specific: uses `FILTER (WHERE ...)` aggregate clauses and `created_at::date` casts — **this query will not run as-is on MySQL**.
- Cache TTL is 120 seconds — after a bulk import/update, stats may lag by up to 2 minutes. There is no manual cache-bust endpoint.

---

## 4. `GET /leads`

Paginated, filterable, searchable list of leads.

### Authorization
- `@authenticated` + `check.module:leads` + `LeadPolicy::viewAny`.

### Query Parameters

| Param | Type | Default | Description |
|---|---|---|---|
| `status` | string | — | Exact match on `status`. `"all"` is treated as "no filter". |
| `temperature` | string | — | Exact match (`cold`/`warm`/`hot`). `"all"` = no filter. |
| `source` | string | — | Exact match on `source`. `"all"` = no filter. |
| `date_from` / `start_date` | date (`Y-m-d`) | — | Lower bound on `created_at` (aliases — `date_from` checked first) |
| `date_to` / `end_date` | date (`Y-m-d`) | — | Upper bound on `created_at` |
| `search` | string | — | See "Search behavior" below |
| `per_page` | int | `20` | Clamped to `[5, 100]` |
| `page` | int | `1` | Standard Laravel pagination |

### Date filter logic (important nuance)

- If **both** `date_from`/`start_date` and `date_to`/`end_date` are present: filters `created_at BETWEEN endOfDay(start) AND endOfDay(end)`. ⚠️ Note: the **start** bound also uses `endOfDay()`, not `startOfDay()` — this is the actual implemented behavior (effectively excludes same-day records created before 23:59:59 on the start date when both bounds are given). If you need inclusive same-day results from midnight, prefer passing only `date_to` or be aware of this off-by-a-day quirk on the lower bound.
- If **only** a start date is given: `created_at >= startOfDay(start)`.
- If **only** an end date is given: `created_at <= endOfDay(end)`.

### Search behavior (`search` param)

The `search` term is matched with **OR** across:
1. `id = <term>` — only if `<term>` is numeric.
2. `source ILIKE '%term%'`
3. `notes ILIKE '%term%'`
4. `payload::text ILIKE '%term%'` (raw JSONB cast — searches **all** dynamic field values/keys)

> Postgres-only (`ILIKE`, `::text` cast). 

### Response

**`200 OK`** — Laravel pagination envelope:
```json
{
  "data": [
    {
      "id": 482,
      "tenant_id": 5,
      "form_id": "9c1b2e1a-1234-4abc-8def-0123456789ab",
      "source": "Website Form",
      "insert_method": "single",
      "temperature": "hot",
      "status": "new",
      "score": null,
      "won": null,
      "payload": {
        "full_name": "John Doe",
        "email": "john@example.com",
        "phone": "+1 555 0100",
        "budget": "10000-20000"
      },
      "meta_data": null,
      "notes": null,
      "created_at": "2026-06-14T09:32:00.000000Z",
      "updated_at": "2026-06-14T09:32:00.000000Z",
      "form": { "id": "9c1b2e1a-1234-4abc-8def-0123456789ab", "name": "Homepage Contact Form" }
    }
  ],
  "links": {
    "first": "{base_url}/api/leads?page=1",
    "last": "{base_url}/api/leads?page=25",
    "prev": null,
    "next": "{base_url}/api/leads?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 25,
    "path": "{base_url}/api/leads",
    "per_page": 20,
    "to": 20,
    "total": 482
  }
}
```

> `form` relation is eager-loaded with only `id, name` columns selected.

### cURL examples

```bash
# Basic list
curl -G "{base_url}/api/leads" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  --data-urlencode "per_page=50"

# Filter: hot leads from Facebook Ads, created in June 2026
curl -G "{base_url}/api/leads" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  --data-urlencode "temperature=hot" \
  --data-urlencode "source=Facebook Ads" \
  --data-urlencode "date_from=2026-06-01" \
  --data-urlencode "date_to=2026-06-30"

# Free-text search across payload + notes + source + numeric ID
curl -G "{base_url}/api/leads" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  --data-urlencode "search=john@example.com"
```

### Ordering & sort stability
Results are ordered `id DESC` (newest-first by primary key, which is faster than `created_at DESC` due to index usage — equivalent ordering since IDs are sequential).

---

## 5. `GET /leads/{lead}`

Full detail view of a single lead, including its activity timeline, originating form, latest AI job status, CRM config, and a "displayable payload" filtered to the form's declared fields.

### Authorization
- `@authenticated` + `check.module:leads` + `LeadPolicy::view` (`view leads` / `leads:view`).
- Cross-tenant access is blocked automatically by the `BelongsToTenant` global scope (a lead from another tenant will 404 via route-model binding, since the scoped query won't find it).

### URL Parameters
| Param | Type |
|---|---|
| `lead` | int (route-model-bound, scoped to tenant) |

### What happens internally

```php
$lead->load(['activities', 'form',
  'latestJob:ai_jobs.id,ai_jobs.job_uuid,ai_jobs.target_id,ai_jobs.target_type,
              ai_jobs.started_at,ai_jobs.completed_at,ai_jobs.status,ai_jobs.attempts']);
$lead->setAttribute('crm_config', $this->getCrmConfig($lead->tenant_id));
$lead->setAttribute('displayable_fields', $lead->getDisplayablePayload());
```

`getDisplayablePayload()`:
- If the originating `form.fields_needed` is empty/null → returns the raw `payload` as-is.
- Otherwise → returns only the keys of `payload` that are also keys in `form.fields_needed` (i.e., filters out internal/bot-detection/noise fields not declared as "needed" by the form schema).

### Response

**`200 OK`**
```json
{
  "id": 482,
  "tenant_id": 5,
  "form_id": "9c1b2e1a-1234-4abc-8def-0123456789ab",
  "source": "Website Form",
  "insert_method": "single",
  "temperature": "hot",
  "status": "new",
  "score": null,
  "won": null,
  "payload": {
    "full_name": "John Doe",
    "email": "john@example.com",
    "phone": "+1 555 0100",
    "budget": "10000-20000",
    "bot_honeypot_filled": null
  },
  "meta_data": {
    "ip_address": "203.0.113.10",
    "user_agent": "Mozilla/5.0 ...",
    "origin": "https://acme.com",
    "referrer": "https://acme.com/contact"
  },
  "notes": null,
  "created_at": "2026-06-14T09:32:00.000000Z",
  "updated_at": "2026-06-14T09:32:00.000000Z",
  "activities": [
    {
      "id": 901,
      "lead_id": 482,
      "type": "system_inserted",
      "content": "Inserted a lead, using SINGLE upload.",
      "metadata": null,
      "created_at": "2026-06-14T09:32:00.000000Z",
      "updated_at": "2026-06-14T09:32:00.000000Z"
    }
  ],
  "form": {
    "id": "9c1b2e1a-1234-4abc-8def-0123456789ab",
    "tenant_id": 5,
    "name": "Homepage Contact Form",
    "schema": [ "..." ],
    "is_active": true,
    "form_source": "system",
    "form_public_url": "https://acme.com/contact",
    "ref_form_id": null,
    "fields_needed": { "full_name": true, "email": true, "phone": true, "budget": true }
  },
  "latest_job": {
    "id": 55,
    "job_uuid": "123e4567-e89b-12d3-a456-426614174000",
    "target_id": 482,
    "target_type": "App\\Models\\Lead",
    "started_at": "2026-06-14T09:32:05.000000Z",
    "completed_at": "2026-06-14T09:32:18.000000Z",
    "status": "completed",
    "attempts": 1
  },
  "crm_config": {
    "entity_name_singular": "Lead",
    "entity_name_plural": "Leads",
    "statuses": [
      { "slug": "new", "label": "New", "color": "blue" },
      { "slug": "contacted", "label": "Contacted", "color": "yellow" },
      { "slug": "closed", "label": "Closed", "color": "green" }
    ]
  },
  "displayable_fields": {
    "full_name": "John Doe",
    "email": "john@example.com",
    "phone": "+1 555 0100",
    "budget": "10000-20000"
  }
}
```

> `latest_job` will be `null` (omitted as `latest_job: null`, key may not appear depending on serialization) if no AI job has ever targeted this lead.

### cURL

```bash
curl -X GET "{base_url}/api/leads/482" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json"
```

### Frontend integration notes
- Use `displayable_fields` (not raw `payload`) when rendering a "Lead details" card meant for end-users — it strips internal bot-detection/tracking fields that shouldn't be shown.
- Use raw `payload` for "raw data" / debug views, or when you need every captured field including ones not declared in the form schema.
- `latest_job.status` can be polled via `GET /ai-jobs/{target_id}/monitor` (Part 10) for real-time AI-processing status (e.g. show a spinner while `status` is `pending`/`processing`).

---

## 6. `GET /leads/{lead}/activities`

Paginated activity/audit log for a single lead (most recent first), 20 per page.

### Authorization
- `@authenticated` + `check.module:leads` + `LeadPolicy::view`.

### URL Parameters
| Param | Type |
|---|---|
| `lead` | int (route-model-bound) |

### Response

**`200 OK`** — Laravel pagination envelope, `data[]` items contain only `id, type, content, created_at`:
```json
{
  "data": [
    {
      "id": 905,
      "type": "system_updated_status",
      "content": "Moved this lead from 'new' to 'contacted' pipeline.",
      "created_at": "2026-06-14T10:00:00.000000Z"
    },
    {
      "id": 904,
      "type": "system_added_note",
      "content": "Customer requested a callback after 5pm.",
      "created_at": "2026-06-14T09:55:00.000000Z"
    },
    {
      "id": 901,
      "type": "system_inserted",
      "content": "Inserted a lead, using SINGLE upload.",
      "created_at": "2026-06-14T09:32:00.000000Z"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 20, "total": 3, "...": "..." }
}
```

### Known activity `type` values

| Type | Trigger |
|---|---|
| `system_inserted` | Created via authenticated app token (Bearer) |
| `external_system_inserted` | Created via developer API Key (non-`api` token) |
| `system_added_note` | Note added via authenticated session |
| `external_system_added_note` | Note added via external system |
| `system_updated_status` / `external_system_updated_status` | `status` field changed |
| `system_updated_temperature` / `external_system_updated_temperature` | `temperature` field changed |
| `mcp_updated` | Updated by the AI sidecar via `/internal/leads/{lead}/update` (Part 10) |
| `message_received` | Inbound WhatsApp message logged (Part 9) |
| `review_replied` | AI-drafted Google review reply posted (Part 9) |
| `system_error` | Webhook loop-protection triggered (see Part 5) |

### cURL

```bash
curl -G "{base_url}/api/leads/482/activities" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  --data-urlencode "page=1"
```

---

## 7. `POST /leads/{lead}/note`

Add a note/activity entry to a lead's timeline.

### Authorization
- `@authenticated` + `check.module:leads` + `LeadPolicy::update` (`update leads` / `leads:update`).

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `content` | string | ✅ | `max:300` |
| `type` | string | ❌ | `in:system_added_note,external_system_added_note` — defaults to `system_added_note` if omitted |

### Response

**`201 Created`**
```json
{ "message": "Note added successfully", "id": 482 }
```
> `id` is the **lead's** ID, not the activity's ID.

### cURL

```bash
curl -X POST "{base_url}/api/leads/482/note" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"content": "Called the customer, scheduled a demo for Friday.", "type": "system_added_note"}'
```

---

## 8. `POST /leads`

Create a single lead. Triggers Eloquent model events (→ `LeadObserver::created` → activity log + webhooks, see Part 5).

### Authorization
- `@authenticated` + `check.module:leads` + `LeadPolicy::create` (`write leads` / `leads:write` + plan limit check).

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation | Notes |
|---|---|---|---|---|
| `payload` | object | ✅ | `array` | Arbitrary key-value data. Each string value is auto-truncated to 5000 chars (`sanitize_payload`). |
| `form_id` | uuid | ❌ | `exists:forms,id` | Associates the lead with a Form |
| `source` | string | ❌ | `max:50` | Defaults to `"undefined"` |
| `temperature` | string | ❌ | `in:cold,warm,hot` | Defaults to `"cold"` |
| `suppress_webhooks` | bool | ❌ | — | If `true`, **no** outgoing webhooks fire for this creation (use when the lead originated from a webhook-driven sync to prevent loops) |

> `status` is **not** accepted in this request — it's hardcoded to `"new"` on creation. `insert_method` is hardcoded to `"single"`.

### Response

**`201 Created`**
```json
{ "message": "Lead created successfully", "id": 483 }
```

**`422 Unprocessable Entity`**
```json
{
  "message": "The given data was invalid.",
  "errors": { "payload": ["The payload field is required."] }
}
```

**`403 Forbidden`** — plan limit reached:
```json
{ "message": "This action is unauthorized." }
```

### cURL

```bash
curl -X POST "{base_url}/api/leads" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "payload": {
      "full_name": "Maria Garcia",
      "email": "maria@example.com",
      "phone": "+1 555 0199",
      "service_interest": "Kitchen Remodel"
    },
    "source": "Manual Entry",
    "temperature": "warm"
  }'
```

### Side effects (LeadObserver, see Part 5 for details)
1. Inserts a `LeadActivity` row describing how the lead was created (`system_inserted` vs `external_system_inserted`, based on whether the auth token is the default `api` token or a developer API key).
2. Fires registered `lead.created` webhooks — **unless** `suppress_webhooks: true` **or** the lead has a `form_id` (form-originated leads fire webhooks via the Form's own webhook relation, not the global lead observer, to avoid double-delivery).
3. May trigger configured **AI Agent triggers** (`AgentTrigger` rows listening for `LeadCreated`-type events) — see Part 7.

---

## 9. `POST /leads/batch`

Bulk-create leads from a JSON array in a single request. Significantly more efficient than N individual `POST /leads` calls — uses chunked bulk `INSERT` (100 rows/chunk) for both leads and their activity log entries.

### Authorization
- `@authenticated` + `check.module:leads` + `LeadPolicy::create`.

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `leads` | array | ✅ | `array, min:1` |
| `leads[*]` | object | ✅ | each element `array` (arbitrary key-values → becomes `payload`) |
| `form_id` | uuid | ❌ | `exists:forms,id` — applied to **all** leads in the batch |
| `source` | string | ❌ | `max:50` — default source for all leads (per-lead `source` key inside `leads[i]` overrides this) |
| `status` | string | ❌ | `max:50` — default status for all leads (per-lead `status` key overrides) |
| `temperature` | string | ❌ | `max:50` — default temperature (per-lead `temperature` key overrides) |
| `from` | string | ❌ | `in:system_inserted,external_system_inserted` — controls the **activity log `type`** recorded for each inserted lead |

### Per-lead overrides

Each object inside `leads[]` may itself contain `source`, `status`, `temperature` keys — these **take priority** over the top-level defaults for that specific lead. Any **other** keys in each lead object become part of that lead's `payload` (sanitized/truncated via `sanitize_payload`).

### What happens internally

For each chunk of up to 100 leads:
1. Bulk `INSERT INTO leads (...)` with `payload` JSON-encoded.
2. Re-query the just-inserted IDs (`WHERE tenant_id=? AND created_at=? ORDER BY id DESC NULLS LAST LIMIT <chunk_size>`).
3. Bulk `INSERT INTO lead_activities (...)` — one row per lead, `content = "Inserted a lead, using BULK upload."`, `type` = the `from` param (default `system_inserted`).

> ⚠️ **No model events fire** for batch/CSV inserts (raw `DB::table()->insert()`, bypassing Eloquent) — so **`LeadObserver` does NOT run**, meaning **no webhooks fire** and **AI agent triggers listening on `LeadCreated` will NOT activate** for batch/import-created leads. If you need webhook/AI-trigger side-effects per lead, use `POST /leads` in a loop instead (at the cost of throughput), or configure a separate polling/sync mechanism.

### Response

**`200 OK`**
```json
{ "message": "Successfully processed 3 leads." }
```

### cURL

```bash
curl -X POST "{base_url}/api/leads/batch" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "source": "n8n Bulk Sync",
    "status": "new",
    "temperature": "cold",
    "from": "external_system_inserted",
    "leads": [
      { "full_name": "Alice Smith", "email": "alice@example.com", "phone": "+1 555 0111" },
      { "full_name": "Bob Jones", "email": "bob@example.com", "phone": "+1 555 0112", "temperature": "hot" },
      { "full_name": "Carla Diaz", "email": "carla@example.com", "source": "Referral" }
    ]
  }'
```

In this example, Alice and Carla get `temperature: cold` (default), Bob gets `hot` (override). Alice and Bob get `source: "n8n Bulk Sync"` (default), Carla gets `"Referral"` (override). All three activity logs are tagged `external_system_inserted`.

---

## 10. `POST /leads/import`

Bulk-create leads from a CSV file upload. Internally reuses the same chunked bulk-insert helper as `/leads/batch`.

### Authorization
- `@authenticated` + `check.module:leads` + `LeadPolicy::create`.

### Request

`Content-Type: multipart/form-data`

| Field | Type | Required | Validation |
|---|---|---|---|
| `file` | file | ✅ | `mimes:csv,txt` |
| `status` | string | ❌ | `max:50`, default `"new"` |
| `temperature` | string | ❌ | `max:50`, default `"cold"` |
| `form_id` | uuid | ❌ | `exists:forms,id` |
| `source` | string | ❌ | `max:50`, default `"undefined"` |
| `from` | string | ❌ | `in:system_inserted,external_system_inserted` |

### CSV processing rules

1. The **first row** is treated as the header row (column names) and is **not** imported as data.
2. Each subsequent row is mapped `column_name => cell_value` via `array_combine($header, $row)` → becomes that lead's `payload`.
3. Rows that are **entirely empty** OR whose **column count doesn't match the header** are **silently skipped**.
4. If the file has no header row at all (empty file), returns `400` immediately:
   ```json
   { "message": "Empty file" }
   ```

> ⚠️ Same caveat as `/leads/batch`: **no Eloquent events fire** — no webhooks, no AI triggers.

### Response

**`200 OK`**
```json
{ "message": "Imported 247 leads." }
```

### cURL

```bash
curl -X POST "{base_url}/api/leads/import" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -F "file=@/path/to/leads.csv" \
  -F "source=CSV Import June 2026" \
  -F "temperature=cold" \
  -F "status=new" \
  -F "from=system_inserted"
```

### Example CSV
```csv
full_name,email,phone,budget
John Doe,john@example.com,+15550100,5000-10000
Jane Roe,jane@example.com,+15550101,10000-20000
```

---

## 11. `PUT /leads/{lead}`

Update a lead's `status`, `temperature`, and/or `notes`. Triggers `LeadObserver::updated` for status/temperature changes (activity logging + webhook firing).

### Authorization
- `@authenticated` + `check.module:leads` + `LeadPolicy::update` (`update leads` / `leads:update`).

### URL Parameters
| Param | Type |
|---|---|
| `lead` | int (route-model-bound) |

### Request

`Content-Type: application/json` — all fields optional (`sometimes`):

| Field | Type | Validation |
|---|---|---|
| `status` | string\|null | `sometimes\|nullable\|max:50` — should match a `crm_config.statuses[].slug` |
| `temperature` | string\|null | `sometimes\|nullable\|in:cold,warm,hot` |
| `notes` | string\|null | `sometimes\|nullable\|in:system_added_note,external_system_added_note` ⚠️ *(see note below — despite the field name "notes", validation restricts it to these two enum values, suggesting this field may be a legacy/typed indicator rather than free text; for adding free-text notes use `POST /leads/{lead}/note` instead)* |
| `suppress_webhooks` | bool | If `true`, sets `$lead->suppress_webhooks = true` before saving — suppresses `LeadObserver` webhook dispatch for this update only |

### Response

**`201 Created`** *(note: 201, not 200, despite being an update)*
```json
{ "message": "Lead updated successfully", "id": 482 }
```

### cURL

```bash
# Move a lead to "contacted"
curl -X PUT "{base_url}/api/leads/482" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"status": "contacted"}'

# Update from an external system without re-triggering webhooks (avoid loops)
curl -X PUT "{base_url}/api/leads/482" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"temperature": "hot", "suppress_webhooks": true}'
```

### Side effects (`LeadObserver::updated`)

When `status` or `temperature` actually changes value (`wasChanged()`):
- A `LeadActivity` row is inserted: `"Moved this lead from '{old}' to '{new}' pipeline."` (status) or `"Updated temperature of this lead from '{old}' to '{new}' pipeline."` (temperature).
- Activity `type` is prefixed `external_` if the request was authenticated via a non-`api` developer token.
- The following webhook events fire (subject to the circuit breakers in Part 5):
  - `lead.updated.status` (if status changed)
  - `lead.updated.temperature` (if temperature changed)
  - `lead.updated` (fires once if **either** changed)

---

## 12. `POST /leads/export`

Stream a CSV export of leads — either all leads for the tenant, or a specific set by ID. Uses a **two-pass discovery + streaming** approach to handle dynamic `payload` columns and large datasets without high memory usage.

### Authorization
- `@authenticated` + `check.module:leads` + `LeadPolicy::viewAny` (`view leads` / `leads:view`).

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `ids` | int[] | ❌ | `sometimes\|nullable\|array\|min:1`; each `ids[*]` is `required\|integer`. If omitted/empty, **all** leads for the tenant are exported. |

### What happens internally

1. **Discovery pass**: cursors through (`->cursor()`, low-memory) all matching leads, collecting the **union of all `payload` keys** across them → `dynamicHeaders`.
2. **Streaming pass**: re-queries (cursor) and streams a CSV with header row:
   ```
   id,status,temperature,source,<dynamicHeader1>,<dynamicHeader2>,...,created_at
   ```
   Each lead's `payload[key]` is written for each dynamic header (empty string if absent); nested arrays/objects are JSON-encoded inline.

### Response

**`200 OK`** — `Content-Type: text/csv`, `Content-Disposition: attachment; filename=leads_export_YYYY-MM-DD_HH-mm.csv`

```csv
id,status,temperature,source,full_name,email,phone,budget,created_at
482,new,hot,Website Form,John Doe,john@example.com,+1 555 0100,10000-20000,2026-06-14 09:32:00
481,contacted,warm,Facebook Ads,Jane Roe,jane@example.com,,5000-10000,2026-06-13 14:02:11
```

### cURL

```bash
# Export ALL leads
curl -X POST "{base_url}/api/leads/export" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: text/csv" \
  -d '{}' \
  -o leads_export.csv

# Export specific leads by ID
curl -X POST "{base_url}/api/leads/export" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: text/csv" \
  -d '{"ids": [481, 482, 483]}' \
  -o selected_leads.csv
```

### Performance notes
- The discovery pass performs a full table scan of `payload` for the selection — for very large tenants (100k+ leads), consider exporting in date-ranged batches by first filtering via `GET /leads` and passing the resulting `ids`.
- CSV is written with `fputcsv(..., separator: ',', enclosure: '"', escape: '')` — explicit empty escape character (PHP 8.4+ deprecation-safe).

---

## 13. Webhooks Emitted by This Module — Quick Reference

(Full details in Part 5.) The Leads module is the primary emitter of tenant webhooks:

| Event | Fired when | NOT fired when |
|---|---|---|
| `lead.created` | `POST /leads` succeeds (Eloquent `created`) | lead has a `form_id` (form's own webhooks handle it instead); `suppress_webhooks: true`; created via `/leads/batch` or `/leads/import` (raw inserts bypass observers) |
| `lead.updated.status` | `PUT /leads/{lead}` changes `status` | `suppress_webhooks: true`; no actual change (`wasChanged()` false) |
| `lead.updated.temperature` | `PUT /leads/{lead}` changes `temperature` | same as above |
| `lead.updated` | either of the above two fired | — |

All webhook dispatch is protected by **two circuit breakers** (per-lead: max 10/min, per-tenant: max 200/min) — exceeding these silently drops further webhook dispatches and logs a `system_error` activity (lead-level) or error log (tenant-level).

---

## 14. Common Integration Recipes

### Recipe: "New lead from a custom landing page (not using the Forms module)"

```bash
curl -X POST "{base_url}/api/leads" \
  -H "Authorization: Bearer $API_KEY_TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "source": "Landing Page - Spring Promo",
    "temperature": "warm",
    "payload": {
      "full_name": "Sam Lee",
      "email": "sam@example.com",
      "promo_code": "SPRING2026"
    }
  }'
```

### Recipe: "Sync a lead status update FROM your CRM back to this platform without re-triggering your own webhook"

```bash
curl -X PUT "{base_url}/api/leads/482" \
  -H "Authorization: Bearer $API_KEY_TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"status": "closed", "suppress_webhooks": true}'
```

### Recipe: "Nightly export for a BI tool"

```bash
curl -X POST "{base_url}/api/leads/export" \
  -H "Authorization: Bearer $API_KEY_TOKEN" \
  -H "Content-Type: application/json" -H "Accept: text/csv" \
  -d '{}' -o "/data/leads_$(date +%Y%m%d).csv"
```

---

**Next:** Part 4 — Forms Module (including the four **public, unauthenticated** form-submission endpoints: native forms, public form viewer, Tally integration, and generic third-party form integration).

> Reply "continue" and I'll proceed to **Part 4: Forms Module**.
