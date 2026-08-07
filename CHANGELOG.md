# Changelog

All notable changes to StoreCrew AI are recorded here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this
project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The plugin is **pre-release**. Everything below is under `[Unreleased]` until
0.1.0 ships; the dated sections are development milestones, not releases.

---

## [Unreleased]

### Added

**Agent framework** — 2026-08-07

- Provider tool-calling across all three families. Anthropic uses `tool_use` /
  `tool_result` content blocks with results on a *user* turn; OpenAI uses a
  `tool_calls` array with a dedicated `tool` role and arguments as a JSON
  *string*; Gemini uses `functionCall` / `functionResponse` parts, assigns no
  call ids at all, and matches results back by tool *name*. Normalised once in
  `Message` rather than at three call sites.
- `ToolInterface` — every tool declares read-or-write, the capability required,
  and whether identity must be proven. The model supplies only which tool and
  what arguments; both are untrusted.
- `ToolExecutor` — **the security boundary**. Authorisation runs in a fixed
  order: tool exists → not disabled → capability held → identity proven → write
  approved → filter veto. `storecrew_tool_authorized` runs last and its return
  is ANDed with the decision already made, so **no filter can grant a permission
  the earlier checks refused** (R-SEC-01).
- `TurnBudget` — three independent ceilings (tool calls, tokens, wall-clock)
  because the failure modes differ. Exhaustion is a recorded `budget_exceeded`
  run status, not a silent short answer (FR-AGENT-06).
- `SharedContext` — structured handoff state rather than a concatenated
  transcript, so a receiving agent inherits conclusions instead of re-deriving
  them (FR-AGENT-03).
- `AgentRunner` — the tool loop. Retrieved content enters as user-role content,
  never as system: a product description is data to reason about, not an
  instruction to obey.
- `Orchestrator` — routes on the cheap `routing` model, and falls through to a
  default agent whenever classification fails. A classifier outage must not cost
  a customer their answer (FR-AGENT-02).
- Four tools: `product.search` (reads live price and stock — the values
  deliberately absent from the index, and drops anything unpurchasable per
  FR-SALES-02), `policy.lookup` (says "not published" rather than letting the
  model invent a returns window), `order.lookup` (identity-gated, and refuses
  orders other than the one verified), `order.note` (the first write, so the
  first requiring approval).
- Sales and Support agents with narrow tool allow-lists — Support cannot search
  the catalogue and Sales cannot touch orders, so an injection that oversteps
  fails at the agent boundary before reaching the executor.
- 68 assertions, driven by a scripted provider.

### Security

- A merchant-edited persona cannot strip the guardrails. Mission and constraints
  are appended *after* the persona, so FR-AGENT-09 cannot be used to disable
  FR-SALES-09.
- Verification proves *an* identity, not *every* identity. `order.lookup`
  refuses an order other than the one confirmed, so verification cannot become a
  skeleton key to the order table (R-SEC-02).

### Changed

- Tools are registered as **factories, resolved on first use and memoised** —
  the same treatment given to REST controllers and job handlers. Constructing
  retrieval and its repositories on every storefront page load, for a visitor
  who never opens the chat widget, is waste. This is the third time eager
  resolution has been caught by the database-free integration harness.

---

### Added

**REST API** — 2026-08-07

- `storecrew/v1` namespace, 18 routes across 7 controllers, contributed through
  a `ControllerRegistry` (`storecrew_register_rest_controllers`). Premium
  registers into the *same* namespace rather than claiming its own, so the admin
  SPA has one API surface and never has to know which plugin owns a route.
- `RestController` base — **every route must declare a capability**. There is no
  default-allow path, so forgetting to think about permissions produces a locked
  route rather than an open one. Feature gating is re-checked server-side; the
  SPA's capability manifest is a rendering hint, and editing it in the browser
  yields a 403 (FR-DIST-09).
- `GET /bootstrap` — everything the SPA needs on first paint in one request,
  including onboarding state. The `canEmbed` flag exists because an
  Anthropic-only install has working chat and cannot index anything, and
  discovering that when indexing silently produces nothing is a bad first hour.
- `GET /health` — environment, queue, index, spend, and encryption key source.
  Every "running" state is judged by **heartbeat rather than stored status**,
  because the failure mode merchants hit is a job that died hours ago while the
  dashboard still reports it as live (FR-ADMIN-08).
- `GET/POST/DELETE /providers/*` — key management. **A stored key is never
  readable through the API again**; responses carry a masked hint only. The
  audit log records that a key was saved, never the key.
- `GET/POST /settings` — model policy and spend cap. Rejects a provider assigned
  to a task it cannot perform, so a bad policy fails at the screen where it was
  set rather than hours later in a background job.
- `GET/POST /index/*` — status, pre-flight cost estimate, start, cancel, and a
  manual embedding drain for after a key is fixed or a cap raised.
- `POST /knowledge/search` — runs retrieval exactly as the agent would and
  reports the strategy, so "the agent gave a bad answer" becomes falsifiable
  (FR-KB-10). POST rather than GET because it embeds the query and therefore
  costs money.
- `GET /conversations/*` — the conversation inspector: turns, runs, retrieval
  traces, and every tool call (FR-ADMIN-04). Addressed by **uuid**, never by
  auto-increment id, so conversation history cannot be enumerated by counting.
- `GET/POST /approvals/*` — the pending-write queue (FR-ADMIN-06).
- 89 assertions dispatched through the real `WP_REST_Server`, so permission
  callbacks and argument validation are exercised rather than bypassed.

### Changed

- REST controllers are registered as **factories, not instances**. The
  registration window still closes at `plugins_loaded` 20 so the contributed set
  is final, but construction defers to `rest_api_init` — building seven
  controllers and the ten repositories behind them on every storefront page load
  is pure waste, and it broke the deliberately database-free integration
  harness. Same root cause as the eager job-handler resolution fixed earlier.

---

### Added

**Background job runner** — `9e09b07`, 2026-08-07

- `Scheduler` wrapping Action Scheduler, scoped to the `storecrew` group so a
  deactivation cancels our work without touching another plugin's queue. Every
  call is guarded; a site without WooCommerce degrades to "background work
  unavailable" rather than fataling.
- `Deadline` sizes a batch from the host's own `max_execution_time` rather than
  a fixed number, and jobs stop *before* starting work they cannot finish. A job
  that chooses to stop leaves a clean cursor; a job that gets killed does not
  (R-TECH-03).
- `IndexJob` — resumable full-index walker. The cursor encodes both extractor
  and position, because "resume from product 4,210" is meaningless once the walk
  has moved on to pages. Refuses to start a second concurrent run.
- `EmbedJob` — drains pending embeddings, backs off on transient provider
  failure, and stops entirely (announcing `storecrew_embedding_blocked`) when
  the reason will not resolve on its own.
- `ReindexJob` — consumes `storecrew_queue_reindex`. Deduplication collapses a
  bulk edit's 500 save hooks into one queued job; an unchanged content hash
  skips queuing an embedding pass at all.
- `MaintenanceJob` — hourly sweep reaping heartbeat-dead index runs and agent
  runs, abandoning stale conversations, and pruning the audit log. Audit
  retention has a 180-day floor so it cannot be quietly disabled.
- 51 assertions against the site's real Action Scheduler.

### Fixed

- **Extractor pagination never advanced past the first batch.** `ids()` asked
  `WP_Query` for the first N ids and *then* filtered to those above the cursor,
  so once the cursor passed that page every batch filtered to nothing, the
  walker concluded the extractor was exhausted, and a full index stopped
  silently after ~20 objects — no error, just a mostly-missing catalogue. The
  cursor now reaches the database through `posts_where`. `9e09b07`
- **`Scheduler::cancel()` missed every action carrying arguments.** Action
  Scheduler only takes its cancel-by-hook fast path when no group is supplied;
  passing one falls through to an args-exact loop, so an empty array cancelled
  only actions that had no arguments. `9e09b07`

### Changed

- Job handlers resolve lazily from the container. Registering them eagerly
  constructed every job and therefore every repository on each request, which
  broke the deliberately database-free integration harness and would have made a
  storefront page load pay to build jobs it will never run. `9e09b07`
- Two assertions that asserted the literal `true` replaced with real ones: audit
  retention cannot be driven below its floor, and double-scheduling the sweep
  does not duplicate it. `9e09b07`

---

### Added

**Knowledge-base pipeline** — `1c45b3f`, 2026-08-07

- `ExtractorInterface` + `ExtractorRegistry` (`storecrew_register_extractors`).
- `ProductExtractor` — **enforces FR-KB-08**. Price, sale price, stock status,
  and stock quantity never enter the index, so the agent cannot quote a stale
  price and a stock edit produces a byte-identical hash that skips re-embedding
  entirely. Also excludes catalogue-hidden and draft products.
- `PostExtractor` — pages and posts, for policy and FAQ grounding
  (FR-SUPPORT-04). Password-protected pages are never indexed.
- `Chunker` — splits on paragraph then sentence boundaries with overlap. Keeps
  the packing target **below** the embedding ceiling: token counts are estimated
  from character length and can be wrong by a third, so target-equals-ceiling
  means one underestimate produces a chunk the API rejects.
- `Indexer` — extract → hash → chunk → embed → store. Chunking and embedding are
  separate stages because one is cheap and local and the other billable and
  remote. A response with the wrong vector count or ragged dimensions is refused
  outright.
- `Retriever` — embeds queries with the **query-side** task type (FR-KB-06) and
  degrades to keyword-only when no embedding provider is configured, reporting
  the degradation rather than silently answering worse.
- Pre-flight cost estimate before indexing starts (R-COST-01).
- 53 assertions, including against a real WooCommerce product.

### Fixed

- A scripted edit passed an `ExtractorRegistry` into `ModelPolicy`'s factory,
  which takes only providers. Caught before commit. `1c45b3f`

---

### Added

**AI provider layer** — `0c829e2`, 2026-08-07

- Five providers behind one interface: Anthropic, OpenAI, Gemini, OpenRouter,
  DeepSeek.
- `Capabilities` — providers declare what they can actually do rather than
  pretending uniformity. **Anthropic rejects `temperature`/`top_p`/`top_k` with
  a 400** and has **no embeddings endpoint**, so chat and embedding are separate
  interfaces and an Anthropic-only install resolves embeddings to `null`.
- `GeminiProvider` implements FR-KB-06's query-vs-document embedding task types
  — using the document type for a query costs recall without erroring anywhere.
- `SecretStore` — envelope encryption. Secrets are encrypted with a data key
  wrapped by a master, so rotating the master re-wraps one blob and leaves every
  secret intact (FR-AI-03). Data-key rotation refuses to proceed at all if any
  secret fails to decrypt first, because a partial rotation would silently
  destroy keys.
- `HttpClient` on the WordPress HTTP API with retry, jittered backoff, and
  `Retry-After` honoured. No bundled client library.
- `ModelPolicy` — per-task model selection (FR-AI-02). `Pricing` — cost
  estimation that reports **unknown rather than zero** for unpriced models.
  `SpendGuard` — hard monthly ceiling (FR-AI-06, R-COST-01).
- 73 assertions, no network calls.

### Changed

- Extracted `HttpClientInterface` mid-build. The providers depended on the
  concrete client, which made request shaping untestable without live API calls
  — and request shaping is where a mistranslated system prompt or role name
  becomes a wrong answer rather than a crash. `0c829e2`

---

### Added

**Repository layer** — `f34afea`, 2026-08-07

- Ten repositories over the eleven tables. Nothing else touches `$wpdb`, which
  is what keeps the vector storage format swappable.
- `Vector` — packed float32 codec and cosine similarity. Returns `0.0` for
  mismatched dimensions rather than throwing, because a corpus mid re-embed
  legitimately holds rows from a previous model.
- Conversations revoke identity verification when the customer changes, so a
  shared device cannot inherit someone else's order access.
- Unconfigured tools default to requiring approval (FR-AGENT-05).
- Index runs judge liveness by heartbeat rather than status, because a killed
  process leaves `status = running` forever.
- Audit stores a salted IP hash, never a raw address.

### Fixed

- **`LEXICAL_FLOOR` was 3**, so any query matching one or two chunks fell
  through to a full dense scan — backwards, because a query matching two chunks
  is *precise*, not failed, and the fallback would have fired constantly on
  exactly the queries the two-stage design exists to serve cheaply. Now 1.
  `f34afea`

---

### Added

**Database schema** — `bc7c528`, 2026-08-07

- Eleven tables, all InnoDB / `utf8mb4`, created by a forward-only migration
  runner that runs on `admin_init` rather than activation — a fatal mid-schema
  during activation leaves a site with no way to retry, and updating by file
  upload never fires the activation hook at all.
- Migration lock uses `add_option` rather than a transient: `option_name`
  carries a unique index so the INSERT either wins or fails atomically, where a
  transient can be served from a shared object cache and handed to two requests.
  Locks older than the TTL are broken rather than honoured.
- `uninstall.php` — drops only what this plugin created, and only when the
  merchant opted in.
- 31 assertions against real MySQL, including a dbDelta drift probe.

### Changed

- Three columns renamed from the schema document and the document corrected:
  `cursor` → `cursor_position` (reserved in MySQL), `authorization` →
  `auth_mode` (reserved in the SQL standard), and `knowledge_sources` keyed on a
  hashed `source_key` rather than a prefixed composite unique, which `dbDelta`
  handles unreliably. Renamed rather than escaped — a column that needs
  backticks forever is a trap. `bc7c528`

---

### Added

**Platform foundation** — `1fc9cf2`, 2026-08-07

- Plugin bootstrap with PHP / WordPress / WooCommerce version guards. Bootstrap
  and `Requirements` stay PHP 5.6-parseable so an unsupported host sees a notice
  rather than a white screen.
- HPOS and Cart & Checkout Blocks compatibility declared (FR-CORE-02/03).
- Hand-written PSR-11 container with circular-dependency detection.
- `ExtensionApi` — the entire add-on contract. Deterministic registration
  window: kernel at `plugins_loaded` 5, `storecrew_api_ready` at 10, registries
  frozen at 20. Writing after freeze throws under `WP_DEBUG`.
- Freezable registries for features and admin routes, tracking which plugin
  contributed each entry.
- `FeatureGate` — server-authoritative entitlement. Computes free-tier truth and
  makes no network calls, which is what keeps the free plugin compliant with the
  WordPress.org rule against calling home.
- Capabilities: `storecrew_manage`, `storecrew_view_analytics`,
  `storecrew_manage_agents`, `storecrew_converse`.
- Integration harness booting both plugins against a hook shim, with no database
  and no WordPress.

### Documentation

- `docs/01-prd.md` — 136 requirements with permanent IDs, competitive analysis,
  performance budgets, risk register.
- `docs/04-database-schema.md` — eleven tables, retention, privacy, the
  two-stage retrieval design answering R-TECH-01.
- `docs/15-free-premium-split.md` — the free/premium boundary and extension API
  contract.

### Security

- The free plugin makes no outbound calls of its own. Licence validation and
  update checks live entirely in the premium plugin.
