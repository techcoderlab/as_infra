# Agency SaaS Platform — Developer API Reference
## Part 4: Forms Module (incl. Public Form Submission Endpoints)

> **Service:** `business-agency-saas-api` (Laravel)
> **Controllers:** `App\Http\Controllers\FormController` (authenticated CRUD), `App\Http\Controllers\PublicFormController` (unauthenticated public endpoints)
> **Model:** `App\Models\Form`
> **Service:** `App\Services\LeadService::processSubmission()`
> **Policy:** `App\Policies\FormPolicy`
> **Base path (authenticated):** `{base_url}/api`
> **Base path (public):** `{base_url}/api/public`
> **Requires module:** `forms` (authenticated endpoints only — public submission endpoints are not module-gated at the route level, but `LeadService` writes leads under the **form's own tenant**, so the tenant's `leads` plan limits still implicitly apply via the underlying Lead creation)

---

## 1. Module Overview

The Forms module is a **lightweight form registry + universal submission funnel**. A `Form` record doesn't enforce a rigid schema server-side (the `schema` JSON column is primarily for the SPA's form-builder UI) — its real job is to act as a **routing key** that maps an inbound submission (from your own hosted form, Tally, Typeform, WordPress, or any third party) to a **tenant** and into the **Leads** pipeline (Part 3), with optional **field re-mapping** via `fields_needed`.

### Endpoints in this module

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/forms` | 🔒 Sanctum | List all forms for current tenant (+ webhooks) |
| `POST` | `/forms` | 🔒 Sanctum | Create a form definition |
| `PUT` | `/forms/{form}` | 🔒 Sanctum | Update a form definition |
| `DELETE` | `/forms/{form}` | 🔒 Sanctum | Delete a form definition |
| `GET` | `/public/form/{uuid}` | 🌐 Public | Fetch a system-hosted form's schema (for rendering) |
| `POST` | `/public/form/{uuid}/submit` | 🌐 Public + rate-limited + tracker-validated | Submit a system-hosted form → creates a Lead |
| `POST` | `/public/tally/form/submit` | 🌐 Public + rate-limited + tracker-validated | Tally.so webhook receiver → creates a Lead |
| `POST` | `/public/external/form/submit` | 🌐 Public + rate-limited + tracker-validated | Generic third-party form webhook receiver → creates a Lead |

---

## 2. Data Model: `Form`

| Field | Type | Notes |
|---|---|---|
| `id` | UUID (v4) | PK, non-incrementing string |
| `tenant_id` | int | auto-injected (`BelongsToTenant`) |
| `name` | string | display name |
| `schema` | json (array) | form-builder field definitions (frontend-defined shape) |
| `is_active` | bool | if `false`, all public endpoints return `404`/`400` |
| `form_source` | enum | `system` \| `tally` \| `typeform` \| `wordpressform` (validated on create/update; `thirdPartyFormSubmit` also accepts arbitrary `form_source` values like `"webflow"`, `"jotform"`, etc., since it doesn't go through the same validation) |
| `form_public_url` | string\|null (URL) | **used for CORS/Origin lock** on public submission — must match the `Origin`/`Referer` header of submissions |
| `ref_form_id` | string\|null | external system's form ID (Tally form ID, third-party form ID, etc.) — used as the lookup key for `tally`/third-party submissions |
| `fields_needed` | json (object)\|null | maps/filters which payload keys are kept; also used by Leads' `displayable_fields` (Part 3 §5) |

### Relations
- `webhooks()` → `Webhook[]` (`hasMany`, FK `form_id`) — see Part 5.

### Cache keys touched by this model
- `form_tracker_{id_or_ref_form_id}` — used by `ValidateTrackerPublicFormRequest` middleware (form lookup for bot/origin checks), TTL 3600s.
- `form_submit_{uuid_or_formId}` — used by `PublicFormController::submit` / `thirdPartyFormSubmit` (form lookup for submission), TTL 120s.
- `form:webhooks:{id}` — used by `Form::triggerWebhooks()` (currently unused — see §6), TTL 5 min.
- All three are cleared via `$form->clearCache()` on update/delete.

---

## 3. `GET /forms`

List all forms for the current tenant, each with a lightweight projection of its webhooks.

### Authorization
- `@authenticated` + `check.module:forms` + `FormPolicy::viewAny` (`view forms` / `forms:view`).

### Response

**`200 OK`** — plain array (not paginated):
```json
[
  {
    "id": "9c1b2e1a-1234-4abc-8def-0123456789ab",
    "tenant_id": 5,
    "name": "Homepage Contact Form",
    "schema": [
      { "name": "full_name", "label": "Full Name", "type": "text", "required": true },
      { "name": "email", "label": "Email", "type": "email", "required": true },
      { "name": "phone", "label": "Phone", "type": "tel", "required": false },
      { "name": "company_name_verification", "label": "", "type": "text", "hidden": true }
    ],
    "is_active": true,
    "form_source": "system",
    "form_public_url": "https://acme.com/contact",
    "ref_form_id": null,
    "fields_needed": { "full_name": true, "email": true, "phone": true },
    "created_at": "2026-05-01T10:00:00.000000Z",
    "updated_at": "2026-05-01T10:00:00.000000Z",
    "webhooks": [
      { "id": 11, "form_id": "9c1b2e1a-1234-4abc-8def-0123456789ab", "name": "CRM Sync", "url": "https://crm.acme.com/hooks/lead", "is_active": true }
    ]
  },
  {
    "id": "a1b2c3d4-5678-4abc-8def-0123456789ab",
    "tenant_id": 5,
    "name": "Tally Booking Form",
    "schema": [],
    "is_active": true,
    "form_source": "tally",
    "form_public_url": null,
    "ref_form_id": "wAbCdE",
    "fields_needed": null,
    "created_at": "2026-05-02T10:00:00.000000Z",
    "updated_at": "2026-05-02T10:00:00.000000Z",
    "webhooks": []
  }
]
```

Ordered by `created_at DESC`.

### cURL

```bash
curl -X GET "{base_url}/api/forms" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

---

## 4. `POST /forms`

Create a form definition / registration.

### Authorization
- `@authenticated` + `check.module:forms` + `FormPolicy::create` (`write forms` / `forms:write`).

### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `name` | string | ✅ | `max:255` |
| `is_active` | bool | ❌ | default `true` |
| `schema` | array | ❌ | nullable; empty array stored if omitted/empty |
| `form_source` | string | ✅ | `in:system,tally,typeform,wordpressform`, `max:20` |
| `form_public_url` | string (URL) | ❌ | nullable, `max:500` — **required in practice for `system` forms** to pass the Origin lock on submission |
| `ref_form_id` | string | ❌ | nullable, `max:200` — **required in practice for `tally`/third-party forms** as the lookup key |
| `fields_needed` | object | ❌ | nullable — `{field_key: true, ...}` map |

### Response

**`201 Created`** — the created `Form` object (same shape as items in §3's array).

### cURL examples

**A "system" form (you build & host the HTML form yourself, submitted to `/public/form/{uuid}/submit`):**
```bash
curl -X POST "{base_url}/api/forms" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "name": "Homepage Contact Form",
    "form_source": "system",
    "is_active": true,
    "form_public_url": "https://acme.com/contact",
    "schema": [
      {"name": "full_name", "label": "Full Name", "type": "text", "required": true},
      {"name": "email", "label": "Email", "type": "email", "required": true},
      {"name": "phone", "label": "Phone", "type": "tel"},
      {"name": "company_name_verification", "label": "", "type": "text", "hidden": true}
    ],
    "fields_needed": {"full_name": true, "email": true, "phone": true}
  }'
```

**A Tally form registration (Tally webhook → `/public/tally/form/submit`):**
```bash
curl -X POST "{base_url}/api/forms" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "name": "Tally Booking Form",
    "form_source": "tally",
    "ref_form_id": "wAbCdE",
    "is_active": true
  }'
```

---

## 5. `PUT /forms/{form}` & `DELETE /forms/{form}`

### `PUT /forms/{form}`

#### Authorization
- `@authenticated` + `check.module:forms` + `FormPolicy::update` (must own the form's `tenant_id`, `update forms` / `forms:update`).

#### Request
Same fields as `POST /forms`, all `sometimes` (optional) **except** `form_source` which is `required` (⚠️ note: unlike `store`, `update` validation marks `form_source` as `required`, even though it's an "update" — you must always re-send `form_source` on every `PUT`).

`schema` is always normalized: if empty/omitted, stored as `[]`.

#### Response
**`200 OK`** — the updated `Form` object.

#### cURL
```bash
# Toggle a form off (e.g., temporarily close it)
curl -X PUT "{base_url}/api/forms/9c1b2e1a-1234-4abc-8def-0123456789ab" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"form_source": "system", "is_active": false}'
```

### `DELETE /forms/{form}`

#### Authorization
- `@authenticated` + `check.module:forms` + `FormPolicy::delete` (must own the form's `tenant_id`, `delete forms` / `forms:delete`).

#### Response
**`204 No Content`**

#### cURL
```bash
curl -X DELETE "{base_url}/api/forms/9c1b2e1a-1234-4abc-8def-0123456789ab" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

> Deleting a form does **not** cascade-delete its associated `Lead` records (`leads.form_id` simply continues to reference a now-deleted form UUID — `lead->form` relation will resolve to `null` on subsequent loads).

---

## 6. Public Endpoint: `GET /public/form/{uuid}`

Fetch a **system-hosted** form's `schema`, for client-side rendering of the form (e.g. by your own marketing site, dynamically). **No auth, no rate limit, no tracker validation** on this `GET` (only the `POST submit` is protected).

### URL Parameters
| Param | Type | Description |
|---|---|---|
| `uuid` | string | The Form's `id` (UUID) |

### Lookup conditions
Returns the form only if **all** of:
- `id = {uuid}`
- `form_source = 'system'`
- `is_active = true`

### Response

**`200 OK`**
```json
{
  "id": "9c1b2e1a-1234-4abc-8def-0123456789ab",
  "name": "Homepage Contact Form",
  "schema": [
    { "name": "full_name", "label": "Full Name", "type": "text", "required": true },
    { "name": "email", "label": "Email", "type": "email", "required": true },
    { "name": "phone", "label": "Phone", "type": "tel" },
    { "name": "company_name_verification", "label": "", "type": "text", "hidden": true }
  ]
}
```

**`404 Not Found`**
```json
{ "message": "This form is not found or temporarily closed." }
```

### cURL

```bash
curl -X GET "{base_url}/api/public/form/9c1b2e1a-1234-4abc-8def-0123456789ab" \
  -H "Accept: application/json"
```

### Frontend integration notes
- Render every field in `schema`, **including** any field marked `"hidden": true` (e.g. `company_name_verification`) — but keep it visually hidden / off-screen via CSS. This is the **honeypot** field consumed by the bot-detection middleware on submit (§9).
- Track time-since-page-load and submit it as `ms_since_load` (see §9) — submissions faster than 2.5 seconds are silently dropped (treated as bots) by the gateway.

---

## 7. Public Endpoint: `POST /public/form/{uuid}/submit`

Submit data for a **system-hosted** form. Creates a `Lead` with `source: "form"`.

### Middleware chain
```
throttle:10,1            # 10 requests / minute per IP
tracker.validate         # ValidateTrackerPublicFormRequest — see §9
```

### URL Parameters
| Param | Type |
|---|---|
| `uuid` | Form `id` |

### Request

`Content-Type: application/json`

Body is **free-form** — every top-level key/value you send becomes a key in the new Lead's `payload` (after `sanitize_payload`: string values truncated to 5000 chars). Recommended shape: match the `schema` field `name`s.

```json
{
  "full_name": "John Doe",
  "email": "john@example.com",
  "phone": "+1 555 0100",
  "company_name_verification": "",
  "ms_since_load": 4200
}
```

> ⚠️ `ms_since_load` and `company_name_verification` (the honeypot) are consumed by the **tracker.validate middleware** (§9) for bot detection — they are **not** stripped from the payload before being saved to the Lead's `payload` JSON in this endpoint (only `thirdPartyFormSubmit` applies `fields_needed`-based filtering). If you don't want these fields cluttering your lead data, set up `fields_needed` on the form (Part 3 §5's `displayable_fields` will then filter them from the UI, though raw `payload` will still contain them).

### What happens internally

1. **`tracker.validate`** middleware runs first (§9) — may short-circuit with a "fake success" `200` for bots, or `400`/`403`/`422` for invalid/closed forms or origin mismatches.
2. Controller looks up the form (cached 120s) by `id = {uuid}, form_source = 'system', is_active = true`.
3. `LeadService::processSubmission($form, $request->all())`:
   - `sanitize_payload()` on the body.
   - Creates `Lead` (`source: 'form'`, `tenant_id` from the form, `form_id` = form's UUID) via `saveQuietly()` (⚠️ **Eloquent observers do NOT fire** — see §10 webhook caveat).
   - Inserts a `LeadActivity` (`type: 'system_form_inserted'`, `content: "Lead created via public ({form_name}) form."`).
   - Dispatches `LeadCreated` event (→ AI agent triggers **do** fire, Part 7).

### Response

**`201 Created`**
```json
{ "message": "Submitted successfully.", "lead_id": 484 }
```

**`404 Not Found`** (after passing tracker validation, but form fetch in controller fails — edge case if form was deactivated between the two cache reads):
```json
{ "message": "Form not found or closed." }
```

**`200 OK`** (bot detected — "fake success", see §9):
```json
{ "status": "success", "meta": "hc" }
```

### cURL

```bash
curl -X POST "{base_url}/api/public/form/9c1b2e1a-1234-4abc-8def-0123456789ab/submit" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Origin: https://acme.com" \
  -d '{
    "full_name": "John Doe",
    "email": "john@example.com",
    "phone": "+1 555 0100",
    "company_name_verification": "",
    "ms_since_load": 4200
  }'
```

---

## 8. Public Endpoint: `POST /public/tally/form/submit`

Receiver for **Tally.so** "Webhook" integration. Configure this URL as the Webhook destination in your Tally form's "Integrations" settings.

### Middleware chain
Same as §7: `throttle:10,1`, `tracker.validate`.

> ⚠️ Tracker validation for this route reads `data.formId` from the body as the lookup key (matched against `forms.ref_form_id`) since there's no `{uuid}` route param. See §9.

### Request

`Content-Type: application/json` — this is **Tally's native webhook payload shape**:

```json
{
  "eventId": "c46a8c5e-...",
  "eventType": "FORM_RESPONSE",
  "createdAt": "2026-06-15T09:00:00.000Z",
  "data": {
    "responseId": "abcd1234",
    "submissionId": "abcd1234",
    "respondentId": "wxyz9876",
    "formId": "wAbCdE",
    "formName": "Booking Form",
    "createdAt": "2026-06-15T09:00:00.000Z",
    "fields": [
      {
        "key": "question_8K9abc",
        "label": "full_name",
        "type": "INPUT_TEXT",
        "value": "Jane Smith"
      },
      {
        "key": "question_aZ1xyz",
        "label": "service_type",
        "type": "DROPDOWN",
        "value": ["opt_123"],
        "options": [
          { "id": "opt_123", "text": "Consultation" },
          { "id": "opt_456", "text": "Follow-up" }
        ]
      },
      {
        "key": "question_multi1",
        "label": "interests",
        "type": "MULTI_SELECT",
        "value": ["opt_a", "opt_b"],
        "options": [
          { "id": "opt_a", "text": "SOP Automation" },
          { "id": "opt_b", "text": "AI Chatbots" },
          { "id": "opt_c", "text": "Web Design" }
        ]
      },
      {
        "key": "question_ms",
        "label": "ms_since_load",
        "type": "INPUT_TEXT",
        "value": "4500"
      },
      {
        "key": "question_hp",
        "label": "company_name_verification",
        "type": "INPUT_TEXT",
        "value": null
      }
    ]
  }
}
```

### What happens internally

1. **Form lookup**: `Form::where('form_source','tally')->where('ref_form_id', $request->input('data.formId'))->where('is_active', true)->first()`. If not found → logs a warning and returns `404`.
2. **Auto-rename**: if `data.formName` differs (case-insensitively) from the stored `Form.name`, the form is silently renamed via `updateQuietly()` (no observer fires, no `updated_at` bump issue with cache — though the form's `form_tracker_*`/`form_submit_*` caches are **not** cleared by `updateQuietly`, so a rename may not reflect immediately in cached lookups for up to 120s/3600s).
3. **Field flattening** (`flattenTallyFields`) — transforms Tally's verbose `fields[]` array into a flat `{label: value}` object:
   - Uses `field.label` as the key (falls back to `field.key` if no label).
   - `DROPDOWN` / `MULTIPLE_CHOICE` → resolves the selected option ID to its `text` via `field.options`.
   - `MULTI_SELECT` → resolves **all** selected option IDs to an array of `text` values.
   - All other types (`INPUT_TEXT`, `INPUT_EMAIL`, `TEXTAREA`, etc.) → raw `value` passthrough.
   - `null` values are preserved as `null`.
4. `LeadService::processSubmission($form, $flattenedData)` — same as §7 (creates Lead with `source: 'form'`, activity type `system_form_inserted` or `external_system_form_inserted` depending on `form_source` — for Tally it'll be `external_system_form_inserted` since `form_source !== 'system'`).

For the example payload above, the resulting lead `payload` would be:
```json
{
  "full_name": "Jane Smith",
  "service_type": "Consultation",
  "interests": ["SOP Automation", "AI Chatbots"],
  "ms_since_load": "4500",
  "company_name_verification": null
}
```

### Response

**`201 Created`**
```json
{ "message": "Tally form submission received." }
```

**`404 Not Found`**
```json
{ "message": "Form not found or closed." }
```

### cURL (simulating a Tally webhook call)

```bash
curl -X POST "{base_url}/api/public/tally/form/submit" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "eventType": "FORM_RESPONSE",
    "data": {
      "formId": "wAbCdE",
      "formName": "Booking Form",
      "fields": [
        {"key":"q1","label":"full_name","type":"INPUT_TEXT","value":"Jane Smith"},
        {"key":"q2","label":"email","type":"INPUT_EMAIL","value":"jane@example.com"},
        {"key":"q3","label":"ms_since_load","type":"INPUT_TEXT","value":"4500"},
        {"key":"q4","label":"company_name_verification","type":"INPUT_TEXT","value":null}
      ]
    }
  }'
```

---

## 9. Public Endpoint: `POST /public/external/form/submit`

Generic receiver for **any third-party form builder** (Webflow, Jotform, WordPress plugins, custom integrations via n8n/Zapier/Make, etc.) that can POST a JSON webhook.

### Middleware chain
Same as §7/§8: `throttle:10,1`, `tracker.validate`.

### Expected request shape

```json
{
  "data": {
    "formId": "wf-form-abc123",
    "formSource": "webflow",
    "ms_since_load": 5200,
    "fields": {
      "full_name": "Robert Brown",
      "email": "robert@example.com",
      "phone": "+1 555 0123",
      "company_name_verification": ""
    }
  }
}
```

| Field | Required | Notes |
|---|---|---|
| `data.formId` | ✅ (recommended) | Looked up against `forms.ref_form_id`. If omitted, a random 6-char string is generated (which will essentially never match any registered form → `404`). |
| `data.formSource` | ❌ | Looked up against `forms.form_source`. Defaults to `"third_party"` if omitted — **register your form with this exact string in `form_source`** (note: `FormController::store` validation only allows `system/tally/typeform/wordpressform` for forms **created via the authenticated API** — so for arbitrary `formSource` strings like `"webflow"` or `"third_party"`, the form record must be created directly in the DB, or `FormController`'s validation `in:` rule needs to be extended to include your custom source) |
| `data.fields` | ✅ | Object of field key → value. **Cannot be empty** → `400 {"message":"No data received."}` |
| `data.ms_since_load` | recommended | Consumed by `tracker.validate` for bot detection |

### What happens internally

1. **Form lookup** (cached 120s, key `form_submit_{formId}`): `Form::where('form_source', $formSource)->where('ref_form_id', $formId)->where('is_active', true)->select(['id','tenant_id','is_active','name','form_source','fields_needed'])->first()`.
2. **Payload filtering** (`filterPayload`):
   - If `form.fields_needed` is set (non-empty array): keeps **only** the keys from `data.fields` that exist as keys in `fields_needed` (via `keys_only()` helper) — i.e., `fields_needed` acts as an **allow-list/mapping** for incoming field names.
   - Otherwise: passes through all of `data.fields`, but strips any `[]` suffix from key names (handles multi-select field names like `interests[]` → `interests`).
3. **Metadata capture** — unlike §7/§8, this endpoint captures rich request metadata into the Lead's `meta_data` column:
   ```json
   {
     "ip_address": "203.0.113.50",
     "user_agent": "Mozilla/5.0 ...",
     "origin": "https://mysite.webflow.io",
     "referrer": "https://mysite.webflow.io/contact",
     "bot_ms_since_load": 5200,
     "bot_honeypot_filled": null
   }
   ```
   `bot_honeypot_filled` is read from `data.fields.company_name_verification`.
4. `LeadService::processSubmission($form, $filteredFields, $metadata)`.

### Response

**`201 Created`**
```json
{ "message": "webflow form submission received." }
```
(the `formSource` value is interpolated into the message)

**`404 Not Found`**
```json
{ "message": "Form not found or closed." }
```

**`400 Bad Request`** — `data.fields` empty:
```json
{ "message": "No data received." }
```

### cURL

```bash
curl -X POST "{base_url}/api/public/external/form/submit" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -H "Origin: https://mysite.webflow.io" \
  -d '{
    "data": {
      "formId": "wf-form-abc123",
      "formSource": "webflow",
      "ms_since_load": 5200,
      "fields": {
        "full_name": "Robert Brown",
        "email": "robert@example.com",
        "phone": "+1 555 0123",
        "company_name_verification": ""
      }
    }
  }'
```

---

## 10. Anti-Spam / Bot Detection & Origin Lock (`ValidateTrackerPublicFormRequest`)

This middleware (`tracker.validate`) guards **all three** `/public/*/submit` endpoints. Understanding it is essential for any frontend that submits to these endpoints.

### Step-by-step logic

```
formId  = data.formId  OR route param {uuid}
formIdKey = "ref_form_id" if data.formId present, else "id"
```

1. **Missing form identifier** → `422 {"message": "Unprocessable Entity. Form ID is required."}`
2. **Form lookup** (cached 1hr, key `form_tracker_{formId}`): `SELECT form_public_url, is_active FROM forms WHERE {formIdKey} = {formId}`.
   - Not found or `is_active = false` → `400 {"message": "Form is not found or closed."}`
3. **Bot detection**:
   - Honeypot value read from `data.fields.company_name_verification` OR top-level `company_name_verification`.
   - `ms_since_load` read from `data.ms_since_load` OR top-level `ms_since_load` — **defaults to `2501`** (i.e., passes the timing check) if entirely absent.
   - If honeypot is **non-empty** OR `ms_since_load < 2500`:
     → returns **`200 OK` `{"status": "success", "meta": "hc"}`** — a **fake success response** that does **not** create a lead, logs an error server-side, but tells the bot/client everything worked (so bots don't adapt).
4. **Origin/Referer lock**:
   - `requestOrigin = Origin header OR Referer header`.
   - If `form.form_public_url` is set AND `requestOrigin` is present:
     - `is_valid_origin($form->form_public_url, $requestOrigin)` — compares **scheme + host** (implementation not shown here, but effectively a same-site check, likely allowing subdomain/path differences).
     - Mismatch → `403 {"message": "Unauthorized action"}`.
     - Match → request proceeds.
   - **If `form.form_public_url` is NOT set, OR no Origin/Referer header is present at all** → `403 {"message": "Unauthorized action"}` (request is **rejected**, not allowed through).

### Practical implications for integrators

| Form type | Must set `form_public_url`? | Must send `Origin`/`Referer`? | Must send `ms_since_load`? |
|---|---|---|---|
| `system` (your own hosted HTML form) | ✅ Yes — must exactly match your page's origin | ✅ Yes (browsers send this automatically for `fetch`/form-post) | ✅ Strongly recommended (≥ 2500) |
| `tally` | ✅ Yes — set to your Tally form's public URL, or the request will be rejected | Tally's webhook sender must send a matching `Origin`/`Referer` — **in practice, server-to-server webhooks (like Tally's) typically do NOT send Origin/Referer headers matching a browser page**, so confirm this works in your environment; if not, this is a known integration friction point | Tally sends `ms_since_load` only if your form includes a hidden field mapped to it |
| third-party (server-to-server webhook, e.g. n8n/Zapier) | Same constraint as above — `form_public_url` + a matching `Origin`/`Referer` header is **required**, or `403` | Must be configured by the calling system (e.g. n8n HTTP Request node "Headers" section must include `Origin: <form_public_url's origin>`) | Recommended — set `>= 2500` to avoid bot-block |

> **Practical workaround for server-to-server integrations** (Tally/n8n/Zapier where you can't guarantee Origin/Referer headers): set `form_public_url` to a URL whose **origin** you configure your webhook sender to also send as a literal `Origin` (or `Referer`) header — most HTTP clients (curl `-H "Origin: ..."`, n8n HTTP node, Zapier custom headers) allow setting arbitrary headers.

---

## 11. ⚠️ Webhook Firing for Form-Originated Leads — Current Behavior

As documented in Part 3 §13, `lead.created` webhooks are **not** fired for leads created through any of the three public submission endpoints in this Part, because:

1. `LeadService::processSubmission()` calls `$lead->saveQuietly()` — this explicitly **suppresses all Eloquent model events**, so `LeadObserver::created` (which is what fires `lead.created` webhooks for `POST /leads`) **never runs**.
2. The `Form::triggerWebhooks()` method **exists** and forms **can** have webhooks attached (visible in `GET /forms`'s `webhooks` relation, manageable via the Webhooks module, Part 5) — but the call to `$form->triggerWebhooks(...)` inside `LeadService::processSubmission()` is **currently commented out** in source.

### What DOES still happen for form-originated leads
- `LeadCreated::dispatch($lead)` **is** called — so **AI Agent triggers** configured to listen for lead-creation events (Part 7) **do** fire for form submissions.
- A `LeadActivity` row is created (`system_form_inserted` / `external_system_form_inserted`).

### Developer guidance
If your integration needs a webhook callback specifically for **form submissions**, you currently have two options:
1. Poll/list leads via `GET /leads?source=form` (Part 3 §4) for new entries.
2. Configure an **AI Agent** with a trigger on lead creation that, as one of its tool actions, calls out to your webhook URL (Part 7) — this works today since `LeadCreated` is dispatched.

(If/when `Form::triggerWebhooks()` is re-enabled in a future deploy, webhooks attached to a `Form` via `POST /webhooks` with `form_id` set — Part 5 — would fire on every submission to that form.)

---

## 12. End-to-End Setup Example: Embedding a System Form on Your Website

```bash
# 1. (Admin) Create the form
curl -X POST "{base_url}/api/forms" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{
    "name": "Contact Us",
    "form_source": "system",
    "form_public_url": "https://acme.com/contact",
    "is_active": true,
    "schema": [
      {"name":"full_name","label":"Full Name","type":"text","required":true},
      {"name":"email","label":"Email","type":"email","required":true},
      {"name":"message","label":"Message","type":"textarea"},
      {"name":"company_name_verification","label":"","type":"text","hidden":true}
    ],
    "fields_needed": {"full_name": true, "email": true, "message": true}
  }'
# → returns {"id": "<form-uuid>", ...}
```

```html
<!-- 2. (Frontend) On https://acme.com/contact -->
<script>
const FORM_UUID = "<form-uuid>";
const loadedAt = Date.now();

document.getElementById('contactForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const body = {
    full_name: document.getElementById('full_name').value,
    email: document.getElementById('email').value,
    message: document.getElementById('message').value,
    company_name_verification: '', // honeypot — must stay empty
    ms_since_load: Date.now() - loadedAt,
  };
  const res = await fetch('https://api.youragency.com/api/public/form/' + FORM_UUID + '/submit', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  const json = await res.json();
  if (json.lead_id) alert('Thanks! We\'ll be in touch.');
});
</script>
```

---

**Next:** Part 5 — Webhooks Module (registration, event types, signature verification, circuit breakers).

> Reply "continue" and I'll proceed to **Part 5: Webhooks Module**.
