# 09 — AI Provider Architecture

**Product:** StoreCrew AI
**Status:** Gate 3 reviewed and remediated — documents the built layer
**Version:** 1.0
**Date:** 2026-08-07

The governing observation: **providers do not differ by parameters; they
differ by dialect and by capability.** A layer that pretends five APIs are
one API with different base URLs produces requests that are silently wrong on
four of them. This layer normalises where normalisation is honest and
declares divergence where it is not. Verified by `verify-providers.php` (82
assertions, no network) and by live calls — several of the facts below are
findable *only* live.

"No network" is a property the suite has to *construct*, and the Gate 3
review found it had stopped doing so honestly. The suite's cleanup was
deleting the merchant's real provider keys, which silently unconfigured a
configured store on every run; downstream suites then passed their
"unconfigured store" probes only because of it, and once the keys were
restored one of those probes ran an embedding job against a live key — a
billable call from inside a test. Both are fixed: secrets and the data key
that unwraps them are snapshotted and restored, and probes that need an
unconfigured store now build that state and put it back. A suite that
mutates a merchant's configuration is not a no-network suite.

---

## 1. Interfaces (FR-AI-01)

**Chat and embedding are separate interfaces**, not methods on one:
`ChatProviderInterface` and `EmbeddingProviderInterface`, both extending a
base `ProviderInterface` (id, label, capabilities, configured, verify).
Default model lists sit on the capability interfaces — `default_models()` on
chat, `default_embedding_models()` on embedding — not on the base, for the
same reason as the split itself: a base-level list would make Anthropic name
embedding models it cannot serve. Anthropic has **no embeddings endpoint** — with one merged
interface, an Anthropic-only install would resolve an "embedding provider"
that fails on first use, hours later, inside a background job. Instead it
resolves to `null`, and the health endpoint says so at configuration time.

Shipped: **Anthropic** (chat), **OpenAI** (chat + embeddings), **Gemini**
(chat + embeddings), **OpenRouter** (chat), **DeepSeek** (chat). All five
register unconditionally — the settings screen must offer a provider before
it can be configured; `is_configured()` gates use, not visibility.

### Capabilities are declarations, not defaults

`Capabilities` is a value object each provider fills truthfully: `chat`,
`embeddings`, `streaming`, `tools`, `sampling`, `prompt_caching`,
`embeddingTaskTypes`, `effort`. Consumers branch on the declaration — the
settings validator refuses an embedding assignment to a `embeddings: false`
provider *at save time*, and the runner requests caching only where declared.
`streaming` describes the *provider* truthfully before this layer can consume
it: `ChatRequest::$stream` exists and is read by nothing yet (§ 6).

One locus worth naming precisely: the save-time guard lives in
`SettingsController::sanitise_policy()`, not in `ModelPolicy` —
`ModelPolicy::save()` persists whatever it is handed. An add-on writing
policy directly gets no defence from the policy object itself.

---

## 2. The Neutral Message Model

`Message` carries `user` / `assistant` / `tool` roles — **deliberately no
`system` role.** Anthropic takes the system prompt as a top-level field;
OpenAI and Gemini each put it somewhere else again. Modelling it as a message
would force every provider to fish it back out; `ChatRequest` carries it
separately instead.

A tool-using exchange is three messages (assistant asks → harness answers →
assistant continues), and every provider models it differently — Anthropic
`tool_use`/`tool_result` content blocks, OpenAI a `tool_calls` array with
`tool`-role replies, Gemini `functionCall`/`functionResponse` parts. The
shape is normalised once here, not at three call sites. A tool result without
its call id throws at construction — providers reject the request anyway, and
the error is better three layers earlier.

`ToolCall` carries an **opaque provider signature**: newer Gemini models
attach a `thoughtSignature` to each `functionCall` and 400 the continuation
unless it is echoed verbatim. Only a live call found this.

---

## 3. Dialect Facts on Record

Each cost a real failure to learn; each is encoded in a provider class and
asserted by a suite:

| Fact | Consequence in code |
|---|---|
| Anthropic 400s on `temperature`/`top_p`/`top_k` | `sampling: false`; `ChatRequest` leaves sampling unset so one request shape is legal everywhere |
| Anthropic has no embeddings endpoint | Interface split (§ 1) |
| Gemini distinguishes query vs document embedding task types | `embeddingTaskTypes: true`; using document-type for a query silently costs recall (FR-KB-06) |
| Gemini `thoughtSignature` must be replayed | Opaque signature on `ToolCall`; round-trip regression probe ("Gemini thought signatures survive the round trip"), so a refactor cannot silently reintroduce the 400 |
| `gemini-embedding-001` is 3072-dim native, requestable down | Dimensions are configuration (`storecrew_embedding_dimensions`, default 1536) — the single largest lever on index size (12 KB/vector at 3072) |
| Gemini 2.5 generation 404s for new keys ("no longer available to new users"); free-tier keys have zero Pro-tier quota (429) | Default model lists name the 3.x line; `agent_runs.error_code` stores the HTTP status enriched with the provider's own code when one was sent (`429:RESOURCE_EXHAUSTED`), so the two failures are distinguishable in support |
| Free-tier request buckets are per-model and opaque (2026-08-08: `generate_content_free_tier_requests`, limit 20, refused `gemini-3.6-flash` chat for ~25 minutes — including a fresh key's first-ever request — while `gemini-3.5-flash` routing kept answering; the "retry in Ns" hint was wrong in both directions, and a window finally opened on the 8th spaced attempt) | A drained chat bucket with a live routing bucket means every turn spends a routing call and then fails — the run record shows it as routed-then-`failed` 429. Support reads the *model* in the quota message, not the retry hint, and treats intermittent 429s as bucket contention, not a broken key. `tools/probe-streaming-delivery.php` is the standing timed-delivery measurement (FR-CHAT-02); when the bucket is dry it reports zero deltas and a served `done` |

**Model identity is point-in-time data.** Every default model list and price
carries a verification date (`Pricing::RATES_VERIFIED`, surfaced in the
admin), because the drift is not hypothetical — it happened twice in this
project's own eight-week window. Proxy catalogues drift too: OpenRouter's
defaults name `google/gemini-3.6-flash`, kept in generation-step with
GeminiProvider's verified list rather than verified against OpenRouter's own
catalogue (2026-08-07) — a wrong id there fails visibly at `verify()`, never
silently.

---

## 4. Model Policy (FR-AI-02)

`ModelPolicy` maps **tasks** — `chat`, `routing`, `embedding`, `summary` — to
`{provider, model}` pairs. Tasks, not call sites: the routing classifier and
a future summariser should be cheap small models regardless of which agent
runs, and the merchant reasons in jobs ("who talks to customers") rather than
in SDK terms. Resolution returns `null` when unconfigured — every consumer
treats that as "degrade gracefully", not as an error to throw.

`fallback()` resolves a failover target but **nothing executes it yet** — the
known gap recorded in 03 § 13; wiring it into `AgentRunner`'s provider-error
path is Phase 2 work.

---

## 5. Cost and Spend (FR-AI-04/06, R-COST-01)

Two rules, both absolute:

- **Unknown is never zero.** `Pricing::estimate()` returns
  `{micros, known}`; unpriced models report `known: false` and every consumer
  propagates it (`costKnown` on estimates, `cost_known` on turns). A
  fabricated zero produces a spend cap that never trips while the merchant
  believes they are protected. Only Anthropic rates are seeded (the ones this
  build verified); everything else is unknown until supplied via the
  `storecrew_model_pricing` filter.
- **The cap is checked before the call** — at all three call sites:
  `AgentRunner` before a chat turn, `Indexer` before an embedding batch, and
  the routing classifier in `Orchestrator`, which is a provider call too and
  would otherwise leak spend on every capped turn. `stop` refuses, `warn`
  proceeds and flags. Reading the cap is not spending it: `SpendGuard::status()`
  computes `blocked` directly rather than via `allows_call()`, whose `warn`
  path fires the breach action — a `/health` GET must never emit spend events.
  Metering (`UsageRepository`) records tokens and cost per
  conversation/provider/model, which is also the free-tier meter's substrate
  (FR-LIC-02) — substrate in the future tense: the counters and
  `within_limit()` exist, nothing records `METRIC_CONVERSATION` yet, and the
  meter itself is unbuilt (per Gate 1 D1 it lives in free when it is).

---

## 6. Transport (`Ai/Http`)

`HttpClientInterface` with one implementation over the WordPress HTTP API —
**no bundled client** (03 § 5): a second Guzzle in a .org plugin collides
with every other plugin's, and `wp_remote_post` honours the proxy constants
and `http_request_args` filters locked-down hosts depend on. Retries with
jittered backoff; `Retry-After` honoured; non-2xx becomes `ProviderException`
carrying the upstream status and message — which is how a 429 and a 404
arrive in the run record distinguishable.

**Both verbs retry, and the GET path is the one that matters to a merchant.**
`get_json()` carries the same discipline as `post_json()` because it is the
credential-verification path for four of five providers: without it, a
transient 503 while checking a key reads as "your API key was rejected", and
the merchant re-pastes a key that was always correct. Probe-tested by
intercepting the transport and failing twice before succeeding.

The interface exists because the providers once depended on the concrete
client, which made **request shaping** untestable without network — and
request shaping is where a mistranslated role becomes a wrong answer rather
than a crash. The suite drives all five providers against a recording double,
including the two thin subclasses: OpenRouter and DeepSeek inherit their
shaping from the OpenAI-compatible base, but the surfaces they *override* —
host and attribution headers — are exactly the parts that inheritance cannot
cover, so those are asserted directly.

Known limitation, shared with the chat surface: `wp_remote_post` cannot
stream. FR-CHAT-02 requires a raw-cURL path with a write callback and a
streaming method on the chat interface — designed as an *addition* (a
`stream()` method alongside `chat()`), not a change to this contract. The
`streaming` capability flag is already declared per provider and already
truthful; what is missing is this layer's ability to act on it, so a consumer
branching on it today gets a `true` that leads nowhere.

---

## 7. Secrets (`SecretStore`, FR-AI-03)

Envelope encryption: secrets encrypted with a data key, the data key wrapped
by a master derived from WordPress salts (source reported in `/health`).
Rotating the master re-wraps one blob and touches no secret. Data-key
rotation **refuses to run** if any existing secret fails to decrypt first — a
partial rotation would silently destroy keys, and tampered ciphertext is a
probe-tested refusal. Keys never leave the server: the REST layer returns a
masked hint (`sk-…3456`) and the suites assert not even a fragment of a
stored key appears in any response or audit row.

---

## 8. Extension

New providers register on `storecrew_api_ready` like everything else
(15 § 4.1) — implement the interface(s), declare capabilities honestly, and
the settings screen, policy validator, health report, and spend metering all
work unmodified. That claim is tested: the suites' scripted providers *are*
third-party providers, registered through the public API.

---

## 9. Traceability

| Requirement / risk | Where |
|---|---|
| FR-AI-01 | § 1 |
| FR-AI-02 | § 4 |
| FR-AI-03 | § 7 |
| FR-AI-04/06, R-COST-01 | § 5 |
| FR-KB-06 | § 3 (task types) |
| FR-CHAT-02, R-TECH-02 | § 6 (streaming gap) |
| FR-DIST-03 | § 8 |
