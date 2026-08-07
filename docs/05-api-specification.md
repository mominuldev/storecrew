# 05 — REST API Specification

**Product:** StoreCrew AI
**Status:** Draft complete — documents the live API as of 2026-08-07
**Version:** 0.1 · Namespace `storecrew/v1` · 21 routes, 8 controllers

Every route below is registered through the controller registry
(15 § 4.1) and dispatched through the real `WP_REST_Server` by
`tests/schema/verify-rest.php` and `verify-chat.php` — including probes that
deliberately call each protected route unauthenticated. The spec therefore
describes verified behaviour, not intent.

---

## 1. Conventions

### 1.1 Envelope

Success: `200`-family with `{ "data": <payload> }`. Error: standard WP error
shape `{ "code": "storecrew_<slug>", "message": "<human sentence>", "data":
{ "status": <int>, ... } }`. Messages are written for humans; codes for
programs; the SPA and widget branch on codes only.

### 1.2 Authentication and authorisation

- **Deny by default.** `RestController::permission()` refuses unless a
  StoreCrew capability is held; there is no route without a permission
  callback, and an unauthenticated route is declared with the deliberately
  named `public_access()` so it is visible at the call site.
- Capabilities: `storecrew_manage` (default), `storecrew_manage_agents`,
  `storecrew_view_analytics`, `storecrew_converse`. Granted to
  administrators and shop managers on activation — the admin surface is not
  `manage_options`-locked (a shop manager runs the crew).
- **Entitlement is re-checked server-side** per request (FR-DIST-09). The
  capability manifest the SPA holds is a rendering hint; editing it in the
  browser earns a 403 here.
- Admin requests authenticate by cookie + `X-WP-Nonce`. The **chat routes do
  not require the nonce**: WordPress treats a cookie-authenticated request
  without one as anonymous, and on a page-cached storefront a stale nonce
  must degrade to "guest", never to "refused".

### 1.3 Identifier discipline

Conversations are addressed by `uuid`, never auto-increment id — sequential
ids would let anyone with the capability enumerate volume. On the public
routes the uuid is only an **address**; possession of the session token is
what authorises (§ 3).

---

## 2. Admin Routes (capability-gated)

### Bootstrap & health

| Route | Method | Purpose |
|---|---|---|
| `/bootstrap` | GET | Everything the SPA needs at first paint: version, API version, feature manifest (free features true, premium false until entitled), admin routes, onboarding state (`canEmbed`, key presence). |
| `/health` | GET | Environment (PHP/WP/Woo versions), queue availability, index health (chunks, embedded, **stranded/mismatched counts** — vectors from another model or width look healthy while scoring 0.0), spend status, encryption key source. |

### Providers

| Route | Method | Purpose |
|---|---|---|
| `/providers` | GET | All five providers with capability flags (`embeddings`, `sampling`, `embeddingTaskTypes`…), configured state, masked `keyHint` (`sk-…3456`), default model lists. **The key itself is never returned, in any form** — probe-tested down to fragments. |
| `/providers/{id}/key` | POST | Store a key (envelope-encrypted). Missing or whitespace key → 400; unknown provider → 404. Audited without the secret. |
| `/providers/{id}/key` | DELETE | Remove the key. |
| `/providers/{id}/verify` | POST | Live round-trip using the stored key; returns `{ok, error}` with the provider's own message on failure. |

### Settings

| Route | Method | Purpose |
|---|---|---|
| `/settings` | GET | Model policy (stored + resolved per task), spend cap status, pricing verification date (`ratesVerified` — a stale rate table is surfaced, not assumed), `canEmbed`, task list, chat settings. |
| `/settings` | POST | Partial update: `modelPolicy`, `spend`, `chat`. Validation is capability-aware — assigning embedding to a chat-only provider is a 400 *here*, not a failure hours later in a background job. Unknown task / provider / spend behaviour → 400. Audited (keys only). |

### Index

| Route | Method | Purpose |
|---|---|---|
| `/index` | GET | Source counts by status, queue health, active run (with heartbeat-derived liveness), recent runs. |
| `/index/estimate` | GET | Pre-flight cost estimate (R-COST-01). Reports `costKnown: false` rather than a fabricated zero when rates are unknown. |
| `/index/start` | POST | Queue a full index run → 202 with `runId`. A live concurrent run → 409. |
| `/index/cancel` | POST | Cancel the active run → 200; nothing to cancel → 409. |
| `/index/embed` | POST | Queue embedding of pending/stranded chunks. |

### Knowledge

| Route | Method | Purpose |
|---|---|---|
| `/knowledge/search` | POST | Retrieval dry-run for FR-KB-10 ("what would the agent have seen?"): results with scores, the strategy used (dense / prefilter), and a `degraded` reason when no embedding provider is live. Empty query → 400. |
| `/knowledge/sources` | GET | Indexed sources with chunk counts and status. |

### Conversations & approvals

| Route | Method | Capability | Purpose |
|---|---|---|---|
| `/conversations` | GET | manage | Paged list (`limit` ≤ 100, `offset`, `status`). |
| `/conversations/{uuid}` | GET | manage | The inspector (FR-ADMIN-04): turns, runs (provider, model, tokens, cost-or-unknown, latency), retrieved chunk **ids and scores only**, every tool call with arguments/result/auth mode. |
| `/approvals` | GET | manage_agents | Pending write actions (FR-ADMIN-06). |
| `/approvals/{id}` | POST | manage_agents | `decision: approve\|deny`. Only a genuinely pending call transitions; anything else → 409 (`not_pending`) — deciding an executed call would falsify the audit trail. |

---

## 3. Public Chat Routes (FR-CHAT)

The only `public_access()` routes. Three checks replace authentication:
feature enabled + store ready, session-token ownership, rate limit.

**Session model.** `POST /chat/session` issues a 64-hex token in an HttpOnly
`SameSite=Lax` cookie *and* returns it once in the body (only when freshly
minted — never echoed back to a caller that already holds one) so the widget
can fall back to the `X-StoreCrew-Session` header on hosts whose page cache
strips `Set-Cookie`. Storage is `sha256(token)`; comparison is
`hash_equals`. Cookie wins over header.

| Route | Method | Behaviour |
|---|---|---|
| `/chat/boot` | GET | Widget configuration: enabled, `ready` (a chat model resolves *and* an agent is available — checked before a conversation can be opened, so a store that cannot answer shows no widget), nonce, `maxChars`, appearance, and the caller's resumed conversation with transcript, or `null`. Never cached; the page itself carries none of this. |
| `/chat/session` | POST | Open or resume. Resume order: token's live conversation first; else the signed-in customer's live conversation (FR-CHAT-05 cross-device), re-bound to the presented token — **the superseded token stops working**. Chat disabled → 403; unready → 503. |
| `/chat/{uuid}/messages` | GET | Transcript (user/assistant rows only — system rows are operator notes and are never exposed). Requires ownership. |
| `/chat/{uuid}/messages` | POST | One turn. Guards in order: enabled/ready → ownership (404) → conversation live (`escalated` still accepts; closed → 409 `conversation_closed`) → non-empty (400) → length ≤ 2000 chars (413) → rate limit (429 with `retryAfter` seconds in `data` — consumed **only here**, so reading a transcript never spends the allowance to speak). Success returns the reply, outcome, and `escalated` flag. A provider failure is still a 200 with a human sentence — degrade, never break. |
| `/chat/{uuid}/close` | POST | Customer ends the conversation. |

**The 404 rule:** no token, unknown uuid, and unowned uuid are
indistinguishable (`storecrew_no_conversation`, 404). Confirming that a
conversation exists is itself a leak.

Rate limits (filterable via `storecrew_chat_rate_limits`): 20/session,
60/hashed-IP per 300 s window. The IP hash is the audit log's salted digest;
no raw address is stored anywhere.

---

## 4. Extension Surface

Premium and third parties add controllers through
`ExtensionApi::controllers()` during the registration window; routes register
at `rest_api_init`, after freeze, so the set is always final (15 § 4.1).
Contributed controllers extend `RestController` and therefore inherit
deny-by-default and the envelope. The shared namespace is deliberate — route
*ownership* is tracked in the registry, not in the URL (docs/README § locked
identifiers).

**API stability:** the envelope, the auth model, the 404 rule, and every
route documented here are covered by the extension API's semver promise from
0.1.0. Additive change is minor; anything else is major.

---

## 5. Traceability

| Requirement | Where honoured |
|---|---|
| FR-ADMIN-04/06 | § 2 conversations & approvals |
| FR-CHAT-01..07 | § 3 (02 pending — see 03 § 8) |
| FR-CHAT-05 | `/chat/session` resume order |
| FR-CHAT-06 | § 3 rate limits |
| FR-SUPPORT-02, R-SEC-02 | § 3 the 404 rule; inspector exposes auth modes |
| FR-KB-09/10 | `/knowledge/search`, `/health` index block |
| FR-AI-06, R-COST-01 | `/index/estimate`, `/settings` spend |
| FR-DIST-09 | § 1.2 server-side entitlement |
| FR-LIC-03 | § 1.2 |
