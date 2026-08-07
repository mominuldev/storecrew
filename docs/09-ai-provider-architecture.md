# 09 — AI Provider Architecture

**Product:** StoreCrew AI
**Status:** Draft complete — documents the built layer as of 2026-08-07
**Version:** 0.1

The governing observation: **providers do not differ by parameters; they
differ by dialect and by capability.** A layer that pretends five APIs are
one API with different base URLs produces requests that are silently wrong on
four of them. This layer normalises where normalisation is honest and
declares divergence where it is not. Verified by `verify-providers.php` (73
assertions, no network) and by live calls — several of the facts below are
findable *only* live.

---

## 1. Interfaces (FR-AI-01)

**Chat and embedding are separate interfaces**, not methods on one:
`ChatProviderInterface` and `EmbeddingProviderInterface`, both extending a
base `ProviderInterface` (id, label, capabilities, configured, verify,
default models). Anthropic has **no embeddings endpoint** — with one merged
interface, an Anthropic-only install would resolve an "embedding provider"
that fails on first use, hours later, inside a background job. Instead it
resolves to `null`, and the health endpoint says so at configuration time.

Shipped: **Anthropic** (chat), **OpenAI** (chat + embeddings), **Gemini**
(chat + embeddings), **OpenRouter** (chat), **DeepSeek** (chat). All five
register unconditionally — the settings screen must offer a provider before
it can be configured; `is_configured()` gates use, not visibility.

### Capabilities are declarations, not defaults

`Capabilities` is a value object each provider fills truthfully: `chat`,
`embeddings`, `tools`, `sampling`, `prompt_caching`, `embeddingTaskTypes`.
Consumers branch on the declaration — the settings validator refuses an
embedding assignment to a `embeddings: false` provider *at save time*, and
the runner requests caching only where declared.

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
| Gemini `thoughtSignature` must be replayed | Opaque signature on `ToolCall` |
| `gemini-embedding-001` is 3072-dim native, requestable down | Dimensions are configuration (`storecrew_embedding_dimensions`, default 1536) — the single largest lever on index size (12 KB/vector at 3072) |
| Gemini 2.5 generation 404s for new keys ("no longer available to new users"); free-tier keys have zero Pro-tier quota (429) | Default model lists name the 3.x line; run records keep provider `error_code` so the two failures are distinguishable in support |

**Model identity is point-in-time data.** Every default model list and price
carries a verification date (`Pricing::RATES_VERIFIED`, surfaced in the
admin), because the drift is not hypothetical — it happened twice in this
project's own eight-week window.

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
- **The cap is checked before the call.** `SpendGuard` gates every chat and
  embedding batch against the monthly ceiling; `stop` refuses, `warn`
  proceeds and flags. Metering (`UsageRepository`) records tokens and cost
  per conversation/provider/model, which is also the free-tier meter's
  substrate (FR-LIC-02).

---

## 6. Transport (`Ai/Http`)

`HttpClientInterface` with one implementation over the WordPress HTTP API —
**no bundled client** (03 § 5): a second Guzzle in a .org plugin collides
with every other plugin's, and `wp_remote_post` honours the proxy constants
and `http_request_args` filters locked-down hosts depend on. Retries with
jittered backoff; `Retry-After` honoured; non-2xx becomes `ProviderException`
carrying the upstream status and message — which is how a 429 and a 404
arrive in the run record distinguishable.

The interface exists because the providers once depended on the concrete
client, which made **request shaping** untestable without network — and
request shaping is where a mistranslated role becomes a wrong answer rather
than a crash. The suite drives every provider against a recording double.

Known limitation, shared with the chat surface: `wp_remote_post` cannot
stream. FR-CHAT-02 requires a raw-cURL path with a write callback and a
streaming method on the chat interface — designed as an *addition* (a
`stream()` capability + method), not a change to this contract.

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
