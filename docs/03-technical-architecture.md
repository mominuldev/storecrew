# 03 — Technical Architecture

**Product:** StoreCrew AI
**Status:** Draft complete — documents the built system as of 2026-08-07
**Version:** 0.1
**Date:** 2026-08-07

Unusually for a Gate 2 document, this describes an architecture that already
exists and has been verified — 583 assertions across nine suites, a DB-free
integration harness, and a live five-turn conversation against a real model.
Where a choice was validated or falsified by running code, the outcome is
recorded here rather than the original guess.

Deep dives live elsewhere: schema in [04](04-database-schema.md), the
extension contract in [15](15-free-premium-split.md), agents in
[08](08-agent-framework.md), providers in [09](09-ai-provider-architecture.md).
This document is the map: the layers, the boundaries between them, and the
reasoning that makes each boundary load-bearing.

---

## 1. Architectural Stance

Three commitments shape everything:

1. **In-process, on hostile hosting.** The plugin runs inside the merchant's
   WordPress on shared hosting that kills long processes, buffers output, and
   runs different PHP versions in web and CLI (R-TECH-03). Every subsystem is
   designed to be interrupted: jobs are chunked and resumable, migrations are
   forward-only and re-entrant, liveness is judged by heartbeat rather than
   status flags.
2. **Degrade, never break** (PRD § 4.4). No AI failure may take down a
   storefront. Concretely: every path out of the chat pipeline returns a
   renderable sentence; provider exceptions become recorded, escalated turns;
   the widget failing to boot leaves a page without a widget, not a page with
   an error.
3. **Boundaries are enforced, not documented.** Each rule below is either
   structurally impossible to violate (a seam the code cannot reach across) or
   probe-tested — deliberately violated once in the suites to confirm the
   guard fires. A rule that has never fired is treated as not existing.

---

## 2. Layer Map

```
┌────────────────────────────────────────────────────────────┐
│  Surfaces      Storefront widget (shadow DOM, 5.3 KB gz)   │
│                Admin SPA (React 19, bundled, no @wordpress)│
├────────────────────────────────────────────────────────────┤
│  REST          storecrew/v1 — 8 controllers, 21 routes     │
│                deny-by-default; 4 public chat routes        │
├────────────────────────────────────────────────────────────┤
│  Application   ChatService · Orchestrator · AgentRunner    │
│                Indexer · Retriever · Jobs                   │
├────────────────────────────────────────────────────────────┤
│  Security      ToolExecutor (the boundary) · SecretStore   │
│                FeatureGate · Capabilities · SpendGuard      │
├────────────────────────────────────────────────────────────┤
│  Providers     Anthropic · OpenAI · Gemini · OpenRouter ·  │
│                DeepSeek — behind Chat/Embedding interfaces  │
│                HttpClientInterface → WP HTTP API            │
├────────────────────────────────────────────────────────────┤
│  Data          10 repositories — the only $wpdb consumers  │
│                11 tables, wp_scr_*, forward-only migrations │
└────────────────────────────────────────────────────────────┘
        Extension API (registries, filters, actions)
        cross-cuts every layer — Deliverable 15
```

---

## 3. Kernel and the Registration Window

The kernel (`src/Plugin.php`) does as little as possible: build the hand-
written PSR-11 container (no autowiring — resolution order is explicit and a
circular dependency is a probe-tested exception), register core contributions,
and run a deterministic registration window:

| `plugins_loaded` priority | Event |
|---|---|
| 5 | Free plugin boots; core features/providers/extractors/tools/agents/controllers registered |
| 6 | Premium handshakes (FR-DIST-04), hooks `storecrew_api_ready` |
| 10 | `storecrew_api_ready` fires — add-ons register |
| 20 | All registries **freeze**; a late write throws under `WP_DEBUG` |

In production a rejected write does not throw — a fatal there would punish
the merchant for an add-on author's mistake. It fires
`do_action( 'storecrew_registry_rejected', $message )` and is otherwise
dropped; nothing listens by default, so a site that wants the rejection in
its logs must hook that action.

Everything downstream reads a stable contribution set. The freeze is what
makes "which plugin contributed this?" answerable, which is what makes the
free/premium boundary auditable (15 § 7).

**Laziness is a correctness property here, not an optimisation.** Controllers
register as factories resolved at `rest_api_init`; tools as factories resolved
at first call; job handlers resolve from the container when the job runs.
Eager construction was reintroduced three separate times and each time built
every repository on every storefront request — caught by the integration
harness, which boots both plugins with no database at all and therefore fatals
the moment anything touches one at registration time.

---

## 4. Data Layer

Full treatment in [04](04-database-schema.md). Architectural rules:

- **Only repositories touch `$wpdb`** — ten of them, over eleven tables.
  Enforced by convention and checked at review; no automated rule exists
  yet. One deliberate carve-out sits outside `Database/`:
  `Knowledge/Extractor/PagesPostTypeIds.php` calls `$wpdb->prepare()` inside
  a `posts_where` filter to add the keyset-pagination cursor `WP_Query`
  cannot express — it augments core's own query rather than touching plugin
  tables, which is what the rule actually protects. (`Tables.php` and the
  migrator also touch `$wpdb`, but they live inside `Database/`.) The payoff
  is substitution: nothing outside `KnowledgeChunkRepository` knows
  embeddings are packed float32 in a LONGBLOB, so the R-TECH-01
  external-vector-store contingency changes one class.
- **WooCommerce data goes through CRUD** (`wc_get_product`, `wc_get_order`),
  never post meta — this is what keeps the declared HPOS compatibility true
  (FR-CORE-02) rather than aspirational.
- **Migrations are forward-only and run on `admin_init`** (plus `init` at
  priority 5 under WP-CLI, which never fires `admin_init`), gated by a
  version comparison in `Migrator::needs_migration()` — not on activation: a fatal mid-schema during activation leaves no
  retry path, and update-by-file-upload never fires activation at all. The
  migration lock is an `add_option` — atomic via the unique index — because a
  transient can be served stale from an object cache to two requests at once.

---

## 5. AI Provider Layer

Full treatment in [09](09-ai-provider-architecture.md). The load-bearing
choices:

- **Chat and embedding are separate interfaces**, because the capability sets
  genuinely differ: Anthropic has no embeddings endpoint, and an
  Anthropic-only install resolves embeddings to `null` rather than to a
  provider that fails on first background use.
- **Capabilities are declared, not assumed.** Anthropic rejects
  `temperature`/`top_p`/`top_k` with a 400 (`sampling: false`); Gemini
  distinguishes query-side from document-side embedding task types (FR-KB-06);
  Gemini tool calls carry a `thoughtSignature` that must be echoed back or the
  continuation 400s. Each of these was found by a live call, which is why the
  architecture treats "verified against the live endpoint on {date}" as a
  first-class annotation.
- **Model identity is volatile.** Live-verified 2026-08-07: Gemini's 2.5
  generation 404s for newly created keys, and free-tier keys have zero quota
  for Pro tiers. Default model lists are point-in-time data with a recorded
  verification date, never truth.
- **No bundled HTTP client.** `HttpClientInterface` over the WordPress HTTP
  API — retry, jittered backoff, `Retry-After` honoured. Bundling Guzzle in a
  .org plugin collides with every other plugin's Guzzle, and `wp_remote_post`
  honours the proxy constants locked-down hosts rely on.
- **Cost reports unknown, never zero** (`Pricing`). A fabricated figure
  produces a spend cap that never trips while the merchant believes they are
  protected (R-COST-01). `SpendGuard` checks the monthly ceiling at each
  spend site — `AgentRunner`, `Indexer`, and the routing classifier in
  `Orchestrator` — before the provider is called. The shipped default is no
  cap until the merchant sets one, and the `warn` behaviour deliberately
  lets calls proceed while firing `storecrew_spend_cap_exceeded`. Under
  `stop` the guarantee is "no further calls": the check runs per call,
  before it, so a single call can still carry the total past the ceiling —
  the cap bounds damage, it is not a to-the-cent budget.
- **Secrets** live in `SecretStore`: envelope encryption, data key wrapped by
  a master, master rotation re-wraps one blob, and data-key rotation refuses
  to proceed if any secret fails to decrypt first — a partial rotation would
  silently destroy keys.

---

## 6. Knowledge Pipeline

Extract → chunk → embed → retrieve, with two measured findings baked in.

- **Extraction** (`ProductExtractor`, `PostExtractor`) **never emits price,
  stock, or order status** (FR-KB-08). One rule, two consequences: the agent
  cannot quote a stale price because no price is in the corpus, and a stock
  edit produces a byte-identical content hash so nothing re-embeds — without
  which a bulk stock update on 5,000 products would re-embed the catalogue
  and bill the merchant for it. Volatile fields are read live at answer time.
- **Indexing** is chunked, resumable, heartbeat-supervised Action Scheduler
  jobs (FR-KB-03, R-TECH-03). Chunks embedded by a different model *or at a
  different width* are treated as needing embedding, so changing either is
  self-healing — exercised for real on 2026-08-07 when 60 stranded vectors
  re-embedded without intervention after a key became available.
- **Retrieval is adaptive, on measured numbers** (FR-KB-09, R-TECH-01).
  Cosine over a 1536-dim vector costs ~90 µs, so a full dense scan is ~91 ms
  at 1,000 chunks and 13.6 s at 150,000. At or below 2,000 embedded chunks,
  every query that has a query vector gets the full scan; above, the lexical
  prefilter. (No embedding provider means no query vector, so retrieval is
  lexical-only at any corpus size.) The current measurement
  (`tools/measure-recall.php`: 23 fixtures over the 62-chunk corpus) scores
  recall@3 0.96 at dense weight 1.0, and the weight sweep is monotonic —
  0.83 at 0.80, 0.91 at 0.90 and 0.95, 0.96 at 1.00. What *demoted* the
  hybrid design was the earlier ten-question set: the lexical arm hurt
  ranking there (recall@3 0.80 vs 1.00 pure-dense — a different, smaller
  corpus, but the same direction) because its score normalises against the
  best match within the candidate set, so the top keyword hit always scores
  1.0 however weak. Default fusion weight is 1.0 — dense only. Large-corpus
  recall remains unmeasured; that case is the external-index contingency
  R-TECH-01 names.
- **Truncation is never silent** — `storecrew_retrieval_truncated` fires
  when the bounded dense-fallback scan hits its `MAX_DENSE_SCAN` ceiling
  (5,000 vectors), the one path on which results can be incomplete without
  the caller knowing. The prefilter's 200-candidate cap is the design
  working as intended, not truncation, and does not fire it.
- Known structural gap: exact-identifier lookup (SKU) fails at every fusion
  weight; it needs its own tool, not semantic retrieval.

---

## 7. Agent Layer and the Security Boundary

Full treatment in [08](08-agent-framework.md). The architecture in brief:

- **Agents are data, not subclasses** (FR-AGENT-01): id, mission, persona,
  guardrails, and a `tool_ids` allow-list. A merchant editing a persona edits
  a row; guardrails are appended *after* the persona so editing cannot strip
  them (probe-tested).
- **`ToolExecutor` is the single security boundary.** Fixed authorisation
  order: tool exists → not disabled for this agent → session capability →
  identity if required → write approval → final filter. The
  `storecrew_tool_authorized` filter **may only deny** — its return is ANDed
  with the prior decision, so no add-on (and no prompt injection reaching an
  add-on) can grant what earlier steps refused (R-SEC-01).
- **Authorisation state never travels through model-shaped data.**
  `ToolContext` is built from the conversation row and the WordPress session;
  identity verification proves *one order* and `order.lookup` refuses any
  other (R-SEC-02); mid-turn verification propagates via a server-side action
  listener, not via anything the model returns.
- **Retrieved content is untrusted input**: it reaches the model as
  tool/user-role content, never as a system instruction. The never-system
  half is unconditional — `Message` carries no system role at all. The
  tool/user half is provider dialect: Anthropic and Gemini wrap tool
  results on a user turn, OpenAI-compatible providers use a dedicated
  `role: 'tool'` message. The system prompt instructs the model to treat
  tool output as data.
- **Turns are budgeted from outside** (`TurnBudget`: tool calls, tokens,
  wall-clock), and exhaustion is a recorded outcome (`budget_exceeded`), not
  an error — visible in the inspector rather than disguised as a short answer.
- **Routing is cheap by design**: a small model on the `routing` task picks
  the owning agent; any routing failure falls through to the default agent,
  because a classifier outage must not cost the customer an answer. The
  classifier call is spend-guarded like every other — a store past its cap
  under `stop` routes straight to the default agent rather than paying to
  classify.

---

## 8. Chat Surface

The only anonymous-facing subsystem (FR-CHAT). Architecture:

- **The uuid is an address, not a credential.** Access requires the session
  token (HttpOnly cookie, `sha256` digest stored); unknown and unowned uuids
  are the same 404.
- **Cache-safety is a design axis**: the storefront page carries one `async`
  script tag and the REST root — no nonce, no state — because Woo storefronts
  are page-cached and anything in the document is served to the next
  thousand visitors. Per-visitor data comes from `/chat/boot` — and "never
  cached" is enforced, not hoped for: `ChatController` marks the whole chat
  surface `Cache-Control: no-store` via a `rest_post_dispatch` filter, so it
  covers all five routes and their error responses. WP core's nocache
  headers apply only to logged-in users, and these routes exist for
  anonymous ones.
- **History is rebuilt server-side each turn**; the client posts one message.
- **Rate limiting is two windows** — per session (tight) and per hashed IP
  (loose backstop) — because either alone is trivially defeated (FR-CHAT-06).
- **The widget** is framework-free TypeScript in a shadow root (5.3 KB gz vs
  the 45 KB budget), WCAG 2.2 AA (dialog semantics, focus trap, live-region
  announcements, reduced-motion). Model output reaches the DOM only through
  `createTextNode`/`createElement` — never `innerHTML`.
- **Unmet: FR-CHAT-02 streaming.** The buffered path is currently used
  everywhere. SSE requires raw cURL with a write callback (out of
  `wp_remote_post`'s reach) and a streaming provider interface. This is the
  largest known architectural gap; R-TECH-02's host-buffering spike remains
  relevant when it is built.

---

## 9. Background Work

Action Scheduler (bundled with WooCommerce — A2) behind a thin `Scheduler`.
Learned constraints, each now encoded:

- `Scheduler::cancel()` must pass **no group** — Action Scheduler only takes
  its cancel-by-hook fast path groupless; passing one matches args exactly and
  misses every job carrying an id.
- Handlers are registered on every request but resolve lazily (§ 3).
- `Deadline` bounds each run under the host's kill window; `MaintenanceJob`
  sweeps stale runs and abandons idle conversations; index-run liveness is
  heartbeat-based because a killed process leaves `status = running` forever.

---

## 10. Admin Application

React 19 + Vite + Tailwind 4, **zero `@wordpress/*` packages** (FR-ADMIN-01);
React is bundled, not borrowed from core, so a core upgrade cannot become an
untested breaking change. Two hard-won wp-admin findings are architectural:

- **Cascade layers lose to wp-admin.** Layer order is consulted before
  specificity, and WordPress admin CSS is unlayered — so Tailwind's layered
  utilities lose to `body { color }` at any specificity. Utilities are
  emitted unlayered and important; preflight is not imported; the reset is
  scoped to `#storecrew-root` at ID specificity. The failure is invisible in
  light mode; **dark mode is the verification surface** for any theme change.
- The SPA renders from a server-supplied capability manifest and never
  decides entitlement (FR-DIST-09); premium routes it cannot load render as
  an upgrade panel without the free plugin knowing what they are.

The storefront widget build is separate (`vite.widget.config.ts`) because the
two surfaces have opposite constraints — an authenticated 300 KB SPA versus a
45 KB-budget script on every product page.

---

## 11. Cross-Cutting: Observability and Audit

- Every agent run records provider, model, tokens, cost (or cost-unknown),
  latency, retrieved chunk ids/scores (ids only — never text, which would
  duplicate the corpus), and every tool call with arguments, result, and
  authorisation mode (FR-ADMIN-04, FR-AGENT-07). Retrieval provenance
  travels by action, not by return path: `Retriever` fires
  `storecrew_retrieval_performed` with its results, and `AgentRunner`
  listens for exactly the duration of the run (detached in a `finally`),
  accumulating each chunk's best score across the turn into
  `SharedContext` — the same server-side listener pattern as mid-turn
  identity verification, chosen so tools need no reference back to the run
  that invoked them.
- Persisted tool arguments are redacted first: `ToolExecutor` blanks
  identity-bearing values (the `email` key, plus a pattern pass over string
  values) before writing, while the tool itself still receives the raw
  value — the run record shows *that* an email was supplied, never which
  one. The `storecrew_redacted_argument_keys` filter can only add keys,
  never remove them.
- The audit log is append-only by design — no update method exists — and
  stores salted IP hashes, never addresses.
- Escalation is a status transition that keeps the conversation open, plus a
  system-role message carrying the structured reason (FR-SUPPORT-07); system
  rows are excluded from both the prompt window and the public transcript.

---

## 12. Testing Architecture

Three distinct harnesses, chosen so that each catches what the others cannot:

| Harness | Runs against | Catches |
|---|---|---|
| `wp eval-file` suites (9) | Real MySQL, real Woo, real REST server, real Action Scheduler | Schema drift, permission gaps, SQL, dbDelta quirks |
| Integration harness | **No database, no WordPress** — a hook shim | Eager construction, undeclared WP dependencies, both-plugin boot order |
| Live verification | Real browser (Playwright), real provider | Cascade fights, provider dialect drift, quota/model-availability failures — the class of bug only a live call finds |

Suite discipline: probe-test every guard; suites snapshot-and-restore any
live option they write (a suite that `delete_option`s its way to cleanliness
wipes a configured store — found the day the first store was configured);
`wp eval-file` runs through `eval()`, so test files carry no
`declare(strict_types=1)`.

Invocation preconditions are part of the contract, not folklore:
`verify-rest.php` and `verify-chat.php` take `--user=1` because their
permission probes deliberately start unauthenticated; `verify-admin.php`
*requires* it — the suite fatals without a user — and additionally requires
storecrew-pro active, because one probe asserts a Pro route reaches the
capability manifest.

---

## 13. Requirement Coverage

| Requirement area | Where architected |
|---|---|
| FR-CORE-02/03 (HPOS, Blocks) | § 4; declared on `before_woocommerce_init` |
| FR-CORE-06/07 (queue, host detection) | § 9 |
| FR-KB-01..10 | § 6 |
| FR-AGENT-01..09 | § 7, [08] |
| FR-CHAT-01..07 | § 8 (02 open) |
| FR-AI-01..06 | § 5, [09] |
| FR-ADMIN-01..08 | § 10 |
| FR-DIST-01..12 | § 3, [15] |
| R-SEC-01/02 | § 7 |
| R-TECH-01/02/03 | § 6, § 8, § 9 |
| R-COST-01 | § 5 |

Open architectural debts, tracked in CLAUDE.md § Known gaps: streaming
(FR-CHAT-02), failover execution (`ModelPolicy::fallback()` resolves but is
never called), large-corpus retrieval (R-TECH-01), SKU lookup, and the
`Pro\Licence` stub.
