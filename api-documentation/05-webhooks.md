# Agency SaaS Platform — Developer API Reference
## Part 5: Webhooks Module

> **Service:** `business-agency-saas-api` (Laravel)
> **Controller:** `App\Http\Controllers\WebhookController`
> **Model:** `App\Models\Webhook`
> **Policy:** `App\Policies\WebhookPolicy`
> **Dispatch Job:** `App\Jobs\DispatchWebhookBatchJob`
> **Emitter:** `App\Observers\LeadObserver`
> **Base path:** `{base_url}/api`
> **Requires module:** `webhooks` (`check.module:webhooks`)
> See **Part 0 §3.4** for the outgoing signature scheme, and **Part 3 §13** / **Part 4 §11** for which Lead/Form actions emit which events.

---

## 1. Module Overview

This module lets a tenant register **outbound HTTP callbacks** ("webhooks") that the platform calls when certain Lead lifecycle events occur. It is currently a **simple 3-endpoint CRUD** — there is no `update`/`PUT` endpoint (delete + recreate to change a webhook), and no delivery-log/retry-inspection UI is exposed via API.

### Endpoints in this module

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/webhooks` | List all webhooks for the current tenant |
| `POST` | `/webhooks` | Register a new webhook |
| `DELETE` | `/webhooks/{id}` | Remove a webhook |

### Authorization model (`WebhookPolicy::checkAccess`)

Same 4-layer pattern as other modules (Part 3 §1):
1. Super admin bypass.
2. `current_tenant_id` must be set.
3. `webhooks` module enabled on tenant's plan.
4. Permission + Sanctum scope:

| Action | Permission | Scope |
|---|---|---|
| `viewAny` (list) | `view webhooks` | `webhooks:view` |
| `create` | `write webhooks` | `webhooks:write` |
| `delete` | `delete webhooks` | `webhooks:delete` |

> Note: `view` and `update` policy methods exist on `WebhookPolicy` (checking `view webhooks`/`update webhooks` + `current_tenant_id === webhook->tenant_id`) but **no corresponding routes/controller methods exist** — i.e., there is no "get single webhook" or "update webhook" endpoint currently wired up, despite the policy supporting it.

---

## 2. Data Model: `Webhook`

| Field | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `tenant_id` | int | auto-injected (`BelongsToTenant`) |
| `name` | string\|null | display label, e.g. `"CRM Sync"` |
| `url` | string | destination URL — **must** be a valid URL (`url` validation rule) |
| `secret` | string\|null | HMAC signing secret — if set, every delivery includes `X-Webhook-Signature` |
| `events` | json (array) | list of event-name strings this webhook subscribes to (see §4) |
| `is_active` | bool | default `true` on creation. **Inactive webhooks are silently excluded** from dispatch (cached lookup filters `is_active = true`) |
| `form_id` | uuid\|null | FK → `forms.id` — optional association to a specific Form (see §6) |
| `created_at` / `updated_at` | timestamps | |

### Relations
- `form()` → `Form` (`belongsTo`).

---

## 3. `GET /webhooks`

List all webhooks for the current tenant, with the associated form's name (if any).

### Authorization
- `@authenticated` + `check.module:webhooks` + `WebhookPolicy::viewAny` (`view webhooks` / `webhooks:view`).

### Response

**`200 OK`** — plain array (not paginated), ordered `created_at DESC NULLS LAST` (Postgres-specific ordering, ensures rows with `null` `created_at` sort last instead of first):

```json
[
  {
    "id": 11,
    "tenant_id": 5,
    "name": "CRM Sync",
    "url": "https://crm.acme.com/hooks/lead",
    "secret": "whsec_8f3a...redacted...",
    "events": ["lead.created", "lead.updated.status"],
    "is_active": true,
    "form_id": "9c1b2e1a-1234-4abc-8def-0123456789ab",
    "created_at": "2026-05-10T12:00:00.000000Z",
    "updated_at": "2026-05-10T12:00:00.000000Z",
    "form": { "id": "9c1b2e1a-1234-4abc-8def-0123456789ab", "name": "Homepage Contact Form" }
  },
  {
    "id": 12,
    "tenant_id": 5,
    "name": "Slack Hot-Lead Alert",
    "url": "https://hooks.slack.com/services/T000/B000/XXXX",
    "secret": null,
    "events": ["lead.updated.temperature"],
    "is_active": true,
    "form_id": null,
    "created_at": "2026-05-11T08:30:00.000000Z",
    "updated_at": "2026-05-11T08:30:00.000000Z",
    "form": null
  }
]
```

> ⚠️ The `secret` field is returned **in plaintext** in this listing — there is no masking/redaction. Treat the `GET /webhooks` response as sensitive; do not expose it to non-admin roles in your frontend without considering this.

### cURL

```bash
curl -X GET "{base_url}/api/webhooks" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

---

## 4. `POST /webhooks`

Register a new webhook subscription.

### Authorization
- `@authenticated` + `check.module:webhooks` + `WebhookPolicy::create` (`write webhooks` / `webhooks:write`).

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `name` | string | ❌ | `nullable\|string\|max:255` |
| `url` | string | ✅ | `required\|url` |
| `secret` | string | ❌ | `nullable\|string\|max:255` — recommended: generate a random 32+ char secret |
| `form_id` | uuid | ❌ | `nullable\|string`, **must exist in `forms.id` AND belong to the current tenant** (validated via `Rule::exists('forms','id')->where('tenant_id', ...)`) |
| `events` | array | ✅ | `required\|array` |
| `events[*]` | string | ✅ | `string` — **not** restricted to a fixed enum (any string is accepted; see §5 for the events the platform actually emits) |

`is_active` is **always** set to `true` on creation (not settable via this endpoint — to disable a webhook, delete it).

### Response

**`201 Created`** — the created `Webhook` object:
```json
{
  "id": 13,
  "tenant_id": 5,
  "name": "n8n Lead Pipeline",
  "url": "https://n8n.acme.com/webhook/abc123",
  "secret": "a1b2c3d4e5f6...",
  "events": ["lead.created"],
  "is_active": true,
  "form_id": null,
  "created_at": "2026-06-15T10:00:00.000000Z",
  "updated_at": "2026-06-15T10:00:00.000000Z"
}
```

**`422 Unprocessable Entity`**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "url": ["The url field must be a valid URL."],
    "events": ["The events field is required."]
  }
}
```

### cURL

```bash
# Generate a secret first (example using openssl)
SECRET=$(openssl rand -hex 32)

curl -X POST "{base_url}/api/webhooks" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{
    \"name\": \"n8n Lead Pipeline\",
    \"url\": \"https://n8n.acme.com/webhook/abc123\",
    \"secret\": \"$SECRET\",
    \"events\": [\"lead.created\", \"lead.updated.status\", \"lead.updated.temperature\"]
  }"

echo "Store this secret to verify signatures: $SECRET"
```

### Multi-event example (Slack alert on hot leads only)

```bash
curl -X POST "{base_url}/api/webhooks" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "name": "Slack Hot-Lead Alert",
    "url": "https://hooks.slack.com/services/T000/B000/XXXX",
    "events": ["lead.updated.temperature"]
  }'
```
> ⚠️ This will fire for **any** temperature change (cold→warm, warm→hot, hot→cold, etc.) — not just transitions *to* "hot". The payload (§5) includes the **new** `temperature` value, so your receiving endpoint must inspect the payload and filter for `"temperature": "hot"` itself if that's the desired behavior.

---

## 5. Event Catalog & Payload Format

### 5.1 Events the platform currently emits

| Event | Emitted by | Trigger condition |
|---|---|---|
| `lead.created` | `LeadObserver::created` | A `Lead` is created via `POST /leads` (Eloquent `created` event fires — i.e. **not** for `/leads/batch`, `/leads/import`, or form-originated leads — see conditions below) |
| `lead.updated.status` | `LeadObserver::updated` | `PUT /leads/{lead}` actually changes the `status` field |
| `lead.updated.temperature` | `LeadObserver::updated` | `PUT /leads/{lead}` actually changes the `temperature` field |
| `lead.updated` | `LeadObserver::updated` | Fires **once**, in addition to the above, whenever **either** status or temperature changed |

### 5.2 Suppression conditions (no webhook fires even if subscribed)

A webhook will **not** be called for a given Lead event if **any** of:
- The request set `suppress_webhooks: true` (Part 3 §8/§11).
- For `lead.created` specifically: the lead has a non-null `form_id` (i.e., it came from a Form submission) — **even though** `Form::triggerWebhooks()` is currently commented out, meaning **form-originated leads currently fire NO webhooks at all** (see Part 4 §11).
- The lead-level circuit breaker has tripped (>10 webhook-triggering events for this lead within 60s).
- The tenant-level circuit breaker has tripped (>200 webhook-triggering events across the tenant within 60s).
- The webhook's `is_active` is `false`.
- The webhook's `events` array doesn't contain the fired event name (exact string match).

### 5.3 Payload shape (the actual HTTP request body your endpoint receives)

```json
{
  "id": 482,
  "payload": {
    "full_name": "John Doe",
    "email": "john@example.com",
    "phone": "+1 555 0100"
  },
  "source": "Manual Entry",
  "temperature": "hot",
  "status": "new",
  "meta_data": null
}
```

| Field | Always present? | Notes |
|---|---|---|
| `id` | ✅ | The Lead's ID |
| `payload` | ✅ | The Lead's full dynamic `payload` (raw, **not** filtered by `fields_needed`) |
| `source` | ✅ | |
| `temperature` | ✅ | Reflects the **current** value at time of dispatch |
| `status` | ✅ | Reflects the **current** value at time of dispatch |
| `meta_data` | ✅ (may be `null`) | |

### ⚠️ Critical integration notes

1. **No `event` field in the body.** The JSON payload is **identical** regardless of whether it was triggered by `lead.created`, `lead.updated.status`, etc. — `Arr::only($lead->toArray(), ['id','payload','source','temperature','status','meta_data'])` is sent verbatim, with **no wrapper, no `event` key, no `timestamp` key**.
   - **If a single webhook subscribes to multiple events**, your receiving endpoint **cannot distinguish which event fired from the payload alone**.
   - **Practical recommendation**: register **separate webhooks** (different `id`s) — one per event type, even pointing to the same URL — and route based on which webhook delivered it, OR have your endpoint maintain its own previous-state cache keyed by lead `id` to diff `status`/`temperature` against the last payload it received for that lead.
2. **No retry/redelivery guarantee in practice.** `DispatchWebhookBatchJob` is queued with `$tries = 3` and `$backoff = [5, 30, 120]`, but the actual HTTP call uses `Http::pool()` **without** `->throw()` — meaning a non-2xx response or connection error does **not** throw an exception, so the **job is considered successful and will NOT be retried** by the queue worker. Treat deliveries as **best-effort, at-most-effective-once**. If you need guaranteed delivery, poll `GET /leads` (Part 3) instead, or build your own outbox pattern on top of `GET /leads/{lead}/activities`.
3. **8-second timeout per delivery** (`->timeout(8)`). Your endpoint must respond within 8 seconds or the connection is aborted (again, silently — no retry).
4. **Concurrent delivery via `Http::pool()`** — if multiple webhooks match the same event, they're all fired **concurrently** in a single pool, not sequentially.

---

## 6. Signature Verification

If `secret` is set on the webhook, every delivery includes:

```http
X-Webhook-Signature: <hex>
User-Agent: AgencySaaS-Webhook/1.0
Content-Type: application/json
```

where:
```
X-Webhook-Signature = HMAC_SHA256(secret, json_encode(payload_body))
```

### Verification example (Node.js / Express)

```javascript
const crypto = require('crypto');

app.post('/hooks/lead', express.raw({ type: 'application/json' }), (req, res) => {
  const signature = req.headers['x-webhook-signature'];
  const expected = crypto
    .createHmac('sha256', process.env.WEBHOOK_SECRET)
    .update(req.body) // raw Buffer of the exact bytes received
    .digest('hex');

  if (signature !== expected) {
    return res.status(401).send('Invalid signature');
  }

  const payload = JSON.parse(req.body.toString('utf8'));
  console.log('Lead event for lead #', payload.id, payload);
  res.status(200).send('ok');
});
```

> ⚠️ **Use the raw request body bytes** for HMAC verification, not a re-serialized JSON object — `json_encode()` on the PHP side and your framework's JSON re-serialization can produce different byte sequences (key order, whitespace), causing false signature mismatches. Most frameworks let you access the raw body via a raw-body middleware (as shown above with `express.raw`).

### Verification example (Python / Flask)

```python
import hmac, hashlib
from flask import request, abort

@app.route('/hooks/lead', methods=['POST'])
def lead_webhook():
    signature = request.headers.get('X-Webhook-Signature', '')
    expected = hmac.new(WEBHOOK_SECRET.encode(), request.data, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(signature, expected):
        abort(401)
    payload = request.get_json()
    # ... process payload['id'], payload['status'], etc.
    return {'ok': True}, 200
```

---

## 7. `DELETE /webhooks/{id}`

Remove a webhook registration.

### Authorization
- `@authenticated` + `check.module:webhooks` + `WebhookPolicy::delete` (must own the webhook's `tenant_id`, `delete webhooks` / `webhooks:delete`).
- Note: lookup is `Webhook::where('tenant_id', current_tenant_id)->where('id', $id)->firstOrFail()` — a webhook belonging to another tenant returns `404` (model not found) **before** the policy even runs.

### Response

**`204 No Content`**

**`404 Not Found`**
```json
{ "message": "No query results for model [App\\Models\\Webhook]." }
```

### cURL

```bash
curl -X DELETE "{base_url}/api/webhooks/12" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

> ⚠️ **There is no `PUT`/`PATCH` endpoint.** To "update" a webhook (e.g., change its `url`, `events`, or rotate its `secret`), delete it and `POST /webhooks` a new one. This means the webhook's `id` will change — update any external references accordingly.

---

## 8. The `tenant:webhooks:{tenant_id}` Cache

`LeadObserver::triggerWebhooks` reads the tenant's active webhooks from a **60-second cache** (`Cache::remember("tenant:webhooks:{tenant_id}", 60, ...)`). This means:

- After `POST /webhooks` (creating a new webhook) or `DELETE /webhooks/{id}`, there can be **up to 60 seconds of lag** before the change is reflected in live Lead-event dispatching.
- There is **no manual cache-bust endpoint** for this — if you need a newly-created webhook to start receiving events immediately for testing, either wait ~60s or trigger a cache-miss by waiting out the TTL.

---

## 9. Circuit Breakers — Detailed Reference

| Breaker | Key | Limit | Window | Effect when tripped |
|---|---|---|---|---|
| Lead-level | `wh:lead:{lead_id}` | 10 webhook-triggering events | 60s rolling | Further events for **this lead** are dropped (not dispatched to any webhook) until the window rolls. On the **11th** attempt only, a `LeadActivity` (`type: system_error`, `content: "Webhooks paused due to loop protection."`) is recorded — subsequent drops within the window are silent. |
| Tenant-level | `wh:tenant:{tenant_id}` | 200 webhook-triggering events | 60s rolling | Further events for **any lead in this tenant** are dropped. Logged server-side only (`Log::error`) — no `LeadActivity` is created. |

### Why this matters for n8n/Zapier loop scenarios

A common failure mode: Webhook A (`lead.updated.status`) points at an automation (e.g. n8n) which, upon receiving the event, calls back `PUT /leads/{lead}` to set a **different** status — which itself fires `lead.updated.status` again — creating an infinite loop.

**Mitigations**:
1. **Always use `suppress_webhooks: true`** (Part 3 §11) when your automation writes back to a lead that originated from a webhook it received — this is the primary, deterministic prevention.
2. The circuit breakers are a **safety net**, not a substitute for #1 — by the time they trip, you've already sent 10+ (lead-level) or 200+ (tenant-level) webhook calls.
3. Monitor `GET /leads/{lead}/activities` for repeated `system_error` entries (`"Webhooks paused due to loop protection."`) as a signal of a misconfigured loop.

---

## 10. `form_id` — Linking a Webhook to a Specific Form

`POST /webhooks` accepts an optional `form_id`. Currently:

- The `form_id` is stored and returned in `GET /webhooks` (with the form's `name` eager-loaded), and `GET /forms` (Part 4 §3) shows each form's associated webhooks.
- **However**, as documented in Part 4 §11, the code path that would make a `form_id`-linked webhook fire specifically on submissions to *that* form (`Form::triggerWebhooks()`) is **currently commented out**. So today, a webhook with `form_id` set behaves **identically** to one without it — it's filtered purely by `events` against tenant-wide Lead events (and per §5.2, `lead.created` for form-originated leads doesn't fire at all regardless).

**Recommendation**: until `Form::triggerWebhooks()` is re-enabled, don't rely on `form_id` for routing — use separate webhook registrations per event type as described in §5, and filter/route on your receiving end using the lead's `payload`/`source` fields if you need form-specific logic.

---

## 11. Full Integration Recipe: n8n Workflow Receiving Lead Events

```bash
# 1. Create an n8n "Webhook" trigger node, copy its Production URL
N8N_WEBHOOK_URL="https://n8n.acme.com/webhook/lead-sync"

# 2. Generate a secret and register the webhook
SECRET=$(openssl rand -hex 32)

curl -X POST "{base_url}/api/webhooks" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{
    \"name\": \"n8n - New Lead Sync\",
    \"url\": \"$N8N_WEBHOOK_URL\",
    \"secret\": \"$SECRET\",
    \"events\": [\"lead.created\"]
  }"

# 3. In n8n, add a "Crypto" or "Function" node to verify X-Webhook-Signature
#    using HMAC-SHA256 with $SECRET against the raw request body, before
#    proceeding with downstream nodes.

# 4. Create a SEPARATE webhook for status changes, pointed at a different
#    n8n webhook node (so you can distinguish event types by URL/path):
curl -X POST "{base_url}/api/webhooks" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d "{
    \"name\": \"n8n - Status Change Sync\",
    \"url\": \"https://n8n.acme.com/webhook/lead-status-sync\",
    \"secret\": \"$SECRET\",
    \"events\": [\"lead.updated.status\"]
  }"

# 5. IMPORTANT: if step 3's workflow ever calls back PUT /leads/{lead},
#    always include "suppress_webhooks": true in that request body.
```

---

## 12. Onboarding Guide Update — Step 9 (Webhooks)

Add the following to the onboarding script's `# --- TODO: Part 5+ ---` section:

```bash
echo "==> [6/?] Registering outbound webhook(s)..."
WEBHOOK_SECRET=$(openssl rand -hex 32)

curl -s -X POST "$BASE_URL/api/webhooks" \
  -H "Authorization: Bearer $OWNER_TOKEN" -H "Content-Type: application/json" \
  -d "{
    \"name\": \"Default Lead Notifier\",
    \"url\": \"https://YOUR-CLIENT-DESTINATION/webhook\",
    \"secret\": \"$WEBHOOK_SECRET\",
    \"events\": [\"lead.created\"]
  }" | jq .

echo "Webhook secret (give to client to verify signatures): $WEBHOOK_SECRET"
```

---

**Next:** Part 6 — AI Chats Module (chat widgets, message history, and the SSE streaming endpoint).

> Reply "continue" and I'll proceed to **Part 6: AI Chats Module**.
