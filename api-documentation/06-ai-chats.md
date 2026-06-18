# Agency SaaS Platform — Developer API Reference
## Part 6: AI Chats Module (Chat Widgets + SSE Streaming)

> **Service:** `business-agency-saas-api` (Laravel) + `business-tools-service` (Python sidecar, called internally)
> **Controller:** `App\Http\Controllers\AiChatController`
> **Models:** `App\Models\AiChat`, `App\Models\ChatMessage`
> **Policy:** `App\Policies\AiChatPolicy`
> **Gateway:** `App\Services\Ai\AiGateway::streamChat()`
> **Base path:** `{base_url}/api`
> **Requires module:** `ai_chats` (`check.module:ai_chats`)
> See **Part 0 §3.3** for the signed-URL mechanism used by the streaming endpoint, and **Part 7** (next) for `AiAgent` configuration (`brain`, `model`, `system_prompt`, `tools`).

---

## 1. Module Overview

An **`AiChat`** is a configured "chat room" / widget definition (think: one entry per chatbot you embed — e.g. "Website Support Bot", "Internal Sales Assistant"). Each `AiChat` is optionally linked to an **`AiAgent`** (Part 7), which defines the LLM provider, model, system prompt, and tool access.

**Two architectures coexist in this controller** — know which one you're using:

| Mode | Status | How it works |
|---|---|---|
| **A. Agent + SSE Streaming** (current/active) | ✅ Active | `POST /ai-chats/{id}/message` saves the user's message and returns a **signed SSE URL**. The frontend opens that URL (`GET /ai-chats/{user}/{aiChat}/chat-stream`), which streams tokens from the `business-tools-service` sidecar (Part 11) in real time and persists the AI's reply. |
| **B. n8n Webhook Proxy** (legacy/inactive) | ⚠️ Route commented out | `chat()` method exists (proxies messages+files to an external `webhook_url`, e.g. an n8n workflow) but its route (`POST /ai-chats/{aiChat}/chat`) is **commented out** in `routes/api.php`. `GET /ai-chats/{id}/status` (health-check of `webhook_url`) **is** still routed and functional, suggesting this mode is being phased out but its config fields (`webhook_url`, `webhook_secret`) remain on the model. |

### Endpoints in this module

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/ai-chats` | 🔒 Sanctum | List all chat configs + available AI agents |
| `POST` | `/ai-chats` | 🔒 Sanctum | Create a chat config |
| `PUT/PATCH` | `/ai-chats/{aiChat}` | 🔒 Sanctum | Update a chat config |
| `DELETE` | `/ai-chats/{aiChat}` | 🔒 Sanctum | Delete a chat config |
| `GET` | `/ai-chats/{aiChat}/history` | 🔒 Sanctum | Cursor-paginated message history (current user only) |
| `GET` | `/ai-chats/{aiChat}/status` | 🔒 Sanctum | Health-check the legacy `webhook_url` |
| `POST` | `/ai-chats/{aiChat}/message` | 🔒 Sanctum | Save a user message, get back a signed stream URL |
| `GET` | `/ai-chats/{user}/{aiChat}/chat-stream` | 🔏 Signed URL only (no Bearer token) | SSE stream of the AI's response |

> ⚠️ **`GET /ai-chats/{aiChat}` (show a single chat) is registered by `Route::apiResource(...)` but `AiChatController` has NO `show()` method.** Calling this endpoint will produce a `500`-class server error (`BadMethodCallException` / method not found on controller). Do not rely on a "get single chat" endpoint — use the `chats` array from `GET /ai-chats` (index) instead.

---

## 2. Data Models

### `AiChat`
| Field | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `tenant_id` | int | auto-injected |
| `name` | string | display name, e.g. `"Website Support Bot"` |
| `ai_agent_id` | int\|null | FK → `ai_agents.id` — the "brain" powering this chat (Part 7) |
| `avatar_url` | string\|null | widget avatar image |
| `welcome_message` | string\|null | shown before the first message |
| `webhook_url` | string\|null (URL) | **legacy mode only** — n8n/external webhook endpoint |
| `webhook_secret` | string\|null | **legacy mode only** — sent as the literal `Authorization` header value (not `Bearer <secret>` — raw value) |

Relation: `agent()` → `AiAgent` (`belongsTo`, FK `ai_agent_id`).

### `ChatMessage`
| Field | Type | Notes |
|---|---|---|
| `id` | int | PK |
| `ai_chat_id` | int | FK → `ai_chats.id` |
| `user_id` | int | the human user in this conversation (the AI's replies are also stored with this `user_id` — i.e. messages are scoped per-(chat, user) pair, **not** globally per chat) |
| `role` | string | `"user"` \| `"ai"` |
| `content` | text | message body |
| `files` | json (array)\|null | `[{type, name, src}]` — only populated for user messages with attachments (legacy `chat()` proxy mode) |

> ⚠️ **No `tenant_id` on `ChatMessage`** — isolation relies entirely on `ai_chat_id` (whose parent `AiChat` is tenant-scoped) + `user_id` filtering in queries. There is no `BelongsToTenant` trait on this model.

---

## 3. Authorization Model (`AiChatPolicy::checkAccess`)

Standard 4-layer pattern:

| Action | Permission | Scope |
|---|---|---|
| `viewAny` (index) | `view ai_chats` | `ai_chats:view` |
| `view` (history, status) | `view ai_chats` (+ `current_tenant_id === aiChat->tenant_id`) | `ai_chats:view` |
| `create` (store) | `write ai_chats` | `ai_chats:write` |
| `update` | `update ai_chats` | `ai_chats:update` |
| `delete` | `delete ai_chats` (+ `current_tenant_id === aiChat->tenant_id`) | `ai_chats:delete` |

> Note: `update()` policy method does **not** check `current_tenant_id === aiChat->tenant_id` (unlike `view`/`delete`) — it only checks the permission+scope. In practice, `AiChat` is tenant-scoped via `BelongsToTenant`'s global query scope, so `PUT /ai-chats/{aiChat}` for an `aiChat` belonging to another tenant would `404` at route-model-binding before the policy is even reached — this gap is likely benign but worth noting for security review.

> **`storeMessage` and `chatStream` have NO `$this->authorize(...)` calls at all.** `storeMessage` runs under the standard authenticated middleware stack (so still requires `auth:sanctum` + `check.module:ai_chats`), but **any authenticated user of the tenant can post to any `AiChat` in that tenant** (no `ai_chats:write`/policy check). `chatStream` runs **entirely outside** `auth:sanctum` (see §7) — its only gate is the signed URL.

---

## 4. `GET /ai-chats`

List all chat configurations for the current tenant, plus the catalog of available AI Agents (for populating an "assign agent" dropdown).

### Authorization
- `@authenticated` + `check.module:ai_chats` + `AiChatPolicy::viewAny`.

### Response

**`200 OK`**
```json
{
  "chats": [
    {
      "id": 3,
      "tenant_id": 5,
      "name": "Website Support Bot",
      "ai_agent_id": 7,
      "avatar_url": "https://acme.com/avatars/bot.png",
      "webhook_url": null,
      "webhook_secret": null,
      "welcome_message": "Hi! How can I help you today?"
    }
  ],
  "agents": [
    { "id": 7, "slug": "support-agent", "is_active": true },
    { "id": 8, "slug": "lead-qualifier-agent", "is_active": true }
  ]
}
```

> `agents` is cached **30 seconds** under the global key `"ai_agents"` (⚠️ **not tenant-scoped in the cache key** — though `AiAgent::select(...)->latest()->get()` is itself subject to `BelongsToTenant`'s global scope, so the cached result for the *first* tenant to hit this within the 30s window could theoretically be served to a different tenant if the cache key collision isn't handled — flag for backend review. In practice the 30s TTL limits exposure.)

### cURL

```bash
curl -X GET "{base_url}/api/ai-chats" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

---

## 5. `POST /ai-chats` & `PUT /ai-chats/{aiChat}`

### `POST /ai-chats` — Create

#### Authorization
- `@authenticated` + `check.module:ai_chats` + `AiChatPolicy::create` (`write ai_chats` / `ai_chats:write`).

#### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `name` | string | ✅ | `max:255` |
| `webhook_url` | string | ❌ | `nullable\|url` |
| `webhook_secret` | string | ❌ | `nullable\|string` |
| `welcome_message` | string | ❌ | `nullable\|string` |
| `ai_agent_id` | int | ❌ | `nullable\|exists:ai_agents,id` |

> `avatar_url` is **not** in the validated/fillable set for `store` — to set an avatar, create then immediately `PUT` (update also doesn't include `avatar_url` in validation either — i.e. **`avatar_url` cannot currently be set via this API** at all; it must be set directly in the DB or via a future endpoint).

#### Response
**`201 Created`** — the created `AiChat` object.

#### cURL
```bash
curl -X POST "{base_url}/api/ai-chats" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "name": "Website Support Bot",
    "welcome_message": "Hi! How can I help you today?",
    "ai_agent_id": 7
  }'
```

### `PUT /ai-chats/{aiChat}` — Update

#### Authorization
- `@authenticated` + `check.module:ai_chats` + `AiChatPolicy::update` (`update ai_chats` / `ai_chats:update`).

#### Request
Same fields as `store`, **all required again** (no `sometimes`):
```json
{
  "name": "Website Support Bot",
  "welcome_message": "👋 Hi! How can I help you today?",
  "ai_agent_id": 8,
  "webhook_url": null,
  "webhook_secret": null
}
```

#### Response
**`200 OK`** — the updated `AiChat` object.

#### cURL
```bash
curl -X PUT "{base_url}/api/ai-chats/3" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "name": "Website Support Bot",
    "welcome_message": "👋 Hi! How can I help you today?",
    "ai_agent_id": 8
  }'
```

---

## 6. `DELETE /ai-chats/{aiChat}`

### Authorization
- `@authenticated` + `check.module:ai_chats` + `AiChatPolicy::delete`.

### Response
**`200 OK`**
```json
{ "message": "Deleted successfully" }
```
*(Note: returns `200`, not `204`, despite no body content needed.)*

> ⚠️ Deleting an `AiChat` does **not** cascade-delete its `ChatMessage` rows at the application level (no `deleting` hook observed) — orphaned messages may remain unless a DB-level `ON DELETE CASCADE` foreign key exists.

### cURL
```bash
curl -X DELETE "{base_url}/api/ai-chats/3" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

---

## 7. The Chat Flow: `POST /ai-chats/{aiChat}/message` → SSE Stream

This is the **primary integration pattern** for embedding an AI chat widget.

### Step 1 — `POST /ai-chats/{aiChat}/message`

Saves the user's message to `chat_messages`, then returns a **5-minute temporary signed URL** for the SSE stream.

#### Authorization
- `@authenticated` + `check.module:ai_chats` (standard stack). **No explicit policy/scope check** (see §3 caveat).

#### Request

`Content-Type: application/json`

| Field | Type | Required | Validation |
|---|---|---|---|
| `text_content` | string | ✅ | `required\|string` |

#### What happens internally
1. `ChatMessage::create(['ai_chat_id' => $aiChat->id, 'user_id' => $user->id, 'role' => 'user', 'content' => $request->text_content])`.
2. Generates `URL::temporarySignedRoute('ai.chat.stream', now()->addMinutes(5), ['user' => $user->id, 'aiChat' => $aiChat->id])`.

#### Response

**`200 OK`**
```json
{
  "stream_url": "https://api.youragency.com/api/ai-chats/12/3/chat-stream?expires=1781234567&signature=8f3a1b2c...d4e5f6"
}
```

#### cURL
```bash
curl -X POST "{base_url}/api/ai-chats/3/message" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"text_content": "What are your business hours?"}'
```

### Step 2 — `GET /ai-chats/{user}/{aiChat}/chat-stream` (the `stream_url`)

Opens a **Server-Sent Events (SSE)** stream that:
1. Resolves the agent: `aiChat->agent` (if `ai_agent_id` set), else falls back to **any** `AiAgent` for the tenant with `is_active = true`.
2. Loads the **last user message** for this chat (the one just saved in Step 1) plus up to `agent->context_window_size` (default 10) prior messages as `history`.
3. Calls `AiGateway::streamChat($agent, $context, $history, $lastUserMessage->content)` — which makes an **HMAC-signed, streaming POST** to the `business-tools-service` sidecar's `/v1/agent/chat` endpoint (Part 11), with `Accept: text/event-stream`.
4. **Passes through** each `data: {...}` SSE event from the sidecar directly to the client, while accumulating `type: "token"` chunks into `$fullAiText`.
5. On stream completion, persists `ChatMessage::create([..., 'role' => 'ai', 'content' => $fullAiText])`.

#### Authorization
- **`signed` middleware ONLY** — this route is registered **outside** the `auth:sanctum` group entirely. No `Authorization: Bearer` header is needed or checked. Security relies entirely on:
  - The URL signature (HMAC over the route + `expires` + `user`/`aiChat` params, using `APP_KEY`).
  - The 5-minute `expires` window.
  - `{user}` and `{aiChat}` being route-model-bound from the URL itself — **whoever holds this URL can stream as that user/chat** until it expires.

#### SSE Event Types Emitted to the Client

| Event | When | Payload shape |
|---|---|---|
| `connected` | Immediately after agent resolution | `data: {"type":"connected"}\n\n` |
| `token` | Each chunk passed through from the sidecar | `data: {"type":"token","data":"<text fragment>"}\n\n` |
| `error` | No active agent found, OR an exception during the sidecar call | `data: {"type":"error","data":"<message>"}\n\n` |
| `done` | Stream finished (success or error) | `data: {"type":"done"}\n\n` |
| *(no user message found)* | If somehow no prior user message exists | only `data: {"type":"done"}\n\n` is sent — no error |

#### cURL (raw SSE consumption — for debugging; browsers should use `EventSource`)

```bash
STREAM_URL="https://api.youragency.com/api/ai-chats/12/3/chat-stream?expires=1781234567&signature=8f3a1b2c...d4e5f6"

curl -N "$STREAM_URL" -H "Accept: text/event-stream"
```

Example raw output:
```
data: {"type":"connected"}

data: {"type":"token","data":"We're"}

data: {"type":"token","data":" open"}

data: {"type":"token","data":" Monday"}

data: {"type":"token","data":"–Friday, 9am to 5pm."}

data: {"type":"done"}

```

#### JavaScript (browser) integration

```javascript
async function sendMessage(aiChatId, text) {
  // Step 1: save message + get signed stream URL
  const res = await fetch(`/api/ai-chats/${aiChatId}/message`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify({ text_content: text }),
  });
  const { stream_url } = await res.json();

  // Step 2: open SSE stream (EventSource cannot send custom headers,
  // so auth is carried entirely by the signed URL)
  const evtSource = new EventSource(stream_url);
  let aiMessage = '';

  evtSource.onmessage = (e) => {
    const msg = JSON.parse(e.data);
    switch (msg.type) {
      case 'connected':
        // show "AI is typing..." indicator
        break;
      case 'token':
        aiMessage += msg.data;
        renderPartialAiMessage(aiMessage); // update UI incrementally
        break;
      case 'error':
        console.error('AI error:', msg.data);
        evtSource.close();
        break;
      case 'done':
        finalizeAiMessage(aiMessage);
        evtSource.close();
        break;
    }
  };

  evtSource.onerror = () => {
    evtSource.close();
  };
}
```

### ⚠️ Critical Notes on the Streaming Endpoint

1. **Gateway nginx config matters**: the gateway's `/tools/v1/agent/*` location block disables proxy buffering specifically for SSE — but `/ai-chats/.../chat-stream` is served by **Laravel itself** (not proxied to `/tools/`). Ensure your Nginx config for the main Laravel upstream also has `proxy_buffering off;` / `X-Accel-Buffering: no` (the controller already sends `X-Accel-Buffering: no` as a response header, which Nginx respects per-response without needing a separate location block — Part 12 will cover gateway specifics).
2. **5-minute expiry**: if the user is slow to open the stream (e.g. mobile app backgrounded), the signed URL can expire before use → the `signed` middleware will reject with `403 Invalid signature`. Re-call `POST /ai-chats/{aiChat}/message` to get a fresh URL — but note this will **re-save** the user's message, creating a duplicate `ChatMessage` row (`storeMessage` has no idempotency guard). Frontend should call Step 1 and **immediately** open Step 2, and treat "expired signature" as a hard failure requiring the user to resend.
3. **`history` is per-(chat, user)**: since `ChatMessage` has no `tenant_id` and queries filter only by `ai_chat_id` (not `user_id`) for `history` in `chatStream`... wait — actually `$lastUserMessage` query filters `role = 'user'` and `ai_chat_id = $aiChat->id` (no `user_id` filter), and `history` likewise has no `user_id` filter. **This means in a multi-user chat room (e.g. a shared internal "Sales Assistant" chat used by multiple staff), the AI's context window mixes messages from ALL users of that `AiChat`** — by contrast, `GET /ai-chats/{aiChat}/history` (§8) **does** filter by `user_id = Auth::id()`. This is a meaningful behavioral difference: the displayed history (§8) is per-user, but the **AI's actual memory/context** (§7) is shared tenant-wide per chat room. For 1:1 "my personal assistant" type chats this is invisible (one user per chat), but for shared team chat rooms, be aware the AI sees everyone's messages.
4. **No AI credit/limit check visible in this code path** — unlike `Tenant::canTenantUseAi()` referenced elsewhere (Part 2 §5), `AiGateway::streamChat()` does not appear to check/decrement `ai_credits_used` for chat streaming specifically. Credit accounting for chat may be handled sidecar-side or not yet wired up — don't assume chat usage is metered against the plan's `ai_credit_limit` based on this code path alone.

---

## 8. `GET /ai-chats/{aiChat}/history`

Cursor-based pagination of this **user's** message history for a given chat, formatted for rendering (e.g. compatible with "Deep Chat" style components).

### Authorization
- `@authenticated` + `check.module:ai_chats` + `AiChatPolicy::view` (`view ai_chats` / `ai_chats:view`, + tenant ownership check).

### Query Parameters

| Param | Type | Description |
|---|---|---|
| `before_id` | int | Cursor — load messages with `id < before_id` (i.e., older messages). Omit for the first/most-recent page. |

### What happens internally
- Fixed page size: **25 messages**.
- Filters `WHERE ai_chat_id = {aiChat} AND user_id = Auth::id()` — **this endpoint IS per-user** (contrast with §7's shared AI context).
- Ordered `id DESC NULLS LAST`, then **reversed** before returning — so `messages[]` is chronological (oldest→newest) within the page.
- `has_more` — `true` if older messages exist beyond this page.
- `next_cursor` — the `id` of the oldest message in this page; pass as `before_id` for the next page.

### Response

**`200 OK`**
```json
{
  "messages": [
    { "role": "user", "text": "Hi, what are your business hours?" },
    { "role": "ai", "text": "We're open Monday–Friday, 9am to 5pm." },
    {
      "role": "user",
      "text": "Can I send you a photo of the issue?",
      "files": [
        { "type": "image", "name": "issue.png", "src": "https://api.youragency.com/storage/tenants/5/chat_uploads/2026/abcd1234.png" }
      ]
    }
  ],
  "has_more": true,
  "next_cursor": 101
}
```

### cURL

```bash
# First page (most recent 25 messages)
curl -X GET "{base_url}/api/ai-chats/3/history" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"

# Next page (older messages)
curl -G "{base_url}/api/ai-chats/3/history" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  --data-urlencode "before_id=101"
```

### Frontend integration notes
- On initial widget load, call this with **no `before_id`** to get the most recent 25 messages (already in chronological order — no client-side reversal needed).
- For "load more / infinite scroll up", call again with `before_id = next_cursor` and **prepend** the returned `messages` to your existing list.
- `files[].type` is one of `"image"`, `"audio"`, `"file"` — render accordingly (`<img>`, `<audio>`, generic download link).

---

## 9. `GET /ai-chats/{aiChat}/status`

Health-check for the **legacy `webhook_url`** (n8n proxy mode). Performs an `OPTIONS` request against `webhook_url` with an 3-second timeout.

### Authorization
- `@authenticated` + `check.module:ai_chats` + `AiChatPolicy::view`.

### Response

**`200 OK`** — no `webhook_url` configured:
```json
{ "status": "inactive", "message": "No webhook URL configured", "latency_ms": null }
```

**`200 OK`** — reachable (HTTP 200/204 from `OPTIONS`):
```json
{ "status": "active", "code": 200, "latency_ms": 142.37 }
```

**`200 OK`** — reachable but unexpected status:
```json
{ "status": "inactive", "code": 404, "latency_ms": 88.12 }
```

**`200 OK`** — unreachable / timeout / exception:
```json
{ "status": "inactive", "message": "Unreachable", "latency_ms": null }
```

> If `webhook_secret` is set, it's sent as the **raw `Authorization` header value** (i.e. `Authorization: <webhook_secret>`, **not** `Authorization: Bearer <webhook_secret>`) — ensure your n8n webhook node's auth expects exactly this format.

### cURL
```bash
curl -X GET "{base_url}/api/ai-chats/3/status" \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

---

## 10. Sequence Diagram: Full Chat Interaction

```
Frontend                  Laravel API                    Sidecar (business-tools-service)
   │                           │                                      │
   │  POST /ai-chats/3/message │                                      │
   │ {text_content: "Hi"}      │                                      │
   ├──────────────────────────►│                                      │
   │                           │ saves ChatMessage(role=user)          │
   │  {stream_url: "...signed"} │                                      │
   │◄──────────────────────────┤                                      │
   │                           │                                      │
   │  GET <stream_url>          │                                      │
   │  (EventSource)             │                                      │
   ├──────────────────────────►│                                      │
   │                           │ resolve AiAgent, build history        │
   │                           │ POST /v1/agent/chat (HMAC-signed,     │
   │                           │ stream=true, Accept: text/event-stream)│
   │                           ├─────────────────────────────────────►│
   │                           │                                       │ LLM call (OpenAI/Gemini/Anthropic)
   │                           │  data: {"type":"token","data":"Hi"}    │
   │ data: {"type":"connected"} │◄─────────────────────────────────────┤
   │◄──────────────────────────┤  data: {"type":"token","data":" there"}│
   │ data: {"type":"token",...} │◄─────────────────────────────────────┤
   │◄──────────────────────────┤  ...                                  │
   │ data: {"type":"token",...} │◄─────────────────────────────────────┤
   │◄──────────────────────────┤  data: {"type":"done"}                │
   │ data: {"type":"done"}      │◄─────────────────────────────────────┤
   │◄──────────────────────────┤                                       │
   │                           │ saves ChatMessage(role=ai, content=   │
   │                           │   accumulated tokens)                 │
```

---

**Next:** Part 7 — AI Agents & Integrations Module (LLM provider credentials, agent configuration: `brain`, `model`, `system_prompt`, `tools`, `tool_configs`, and trigger-based automation).

> Reply "continue" and I'll proceed to **Part 7: AI Agents & Integrations Module**.
