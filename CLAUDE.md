# StoreCrew AI — working notes

The AI employee platform for WooCommerce, by Decent Themes. This file is the
orientation a new session needs before touching anything. Read it first.

**This is the free plugin.** The premium add-on lives at `../storecrew-pro/`
and has its own `CLAUDE.md`.

---

## Locked identifiers — never change these

| Concern | Value |
|---|---|
| Product name | StoreCrew AI |
| WP.org slug | `storecrew` |
| PHP namespace | `StoreCrew\` |
| Text domain | `storecrew` |
| DB table prefix | `wp_scr_` |
| REST namespace | `storecrew/v1` |
| CSS / JS prefix | `scr-` |
| Option prefix | `storecrew_` |
| Hook prefix | `storecrew_` |

The name was chosen by the product owner over a recorded objection:
`storecrew.com` is a live e-commerce site and the `Store*` AI cluster
(StoreClerk, StoreClaw, Storee, StoreDNA, StorePilot) is dense. That is
**settled** — do not reopen it. It is tracked as R-BRAND-01 in the PRD, and the
launch domain must differ from `storecrew.com`.

---

## Architecture-first, phase-gated

Documents come before code. `docs/README.md` is the index and tracks gate
status. Requirement IDs (`FR-KB-08`, `R-TECH-03`, …) are **permanent** — code
and commits reference them, so never renumber.

**All fifteen deliverables are approved at v1.0 — all five gates,
2026-08-07.** Each gate was reviewed *against the code*, remediated, and
ratified; the findings, fixes, and decisions live in `docs/reviews/`
(gate-2 through gate-5). The governing list now is **14 § M1's exit
criteria** — retention pruning, GDPR hooks, streaming, SKU tool, failover,
guardrail overrides, static-analysis configs, and the rest — which is the
single path between here and beta. Two defect shapes every gate re-found;
check new work against both before calling it done:
**built-but-unconsumed** (shipped, read by nothing — the capability
manifest, the feature catalog, `METRIC_CONVERSATION`) and **substrate
reported as capability** (per-run counting described as conversation
metering; repository delete methods described as retention). A code change
that alters documented
behaviour edits the document in the same change-set.

Deliverable 15 is numbered out of sequence deliberately — it was added after
the original 14 and appending beat renumbering every cross-reference.

---

## The rules that are load-bearing

These are not style preferences. Each exists because violating it produces a
**silent wrong answer** rather than a crash.

### 1. Price and stock never enter the index (FR-KB-08)

`ProductExtractor` must never emit a price, sale price, stock status, or stock
quantity. Volatile fields are read live at request time and injected into the
prompt separately.

Two consequences, and they are the same fact: the agent cannot quote a stale
price because a stale price is not in the corpus, **and** a stock edit produces
a byte-identical content hash so nothing re-embeds. Without the second, a bulk
stock update on 5,000 products re-embeds the whole catalogue and bills the
merchant for it.

### 2. Anthropic rejects sampling parameters

`temperature`, `top_p`, and `top_k` return a **400** on current Anthropic
models. `AnthropicProvider` never sends them and declares `sampling: false`.
Anthropic also has **no embeddings endpoint** — chat and embedding are separate
interfaces for that reason, and an Anthropic-only install resolves embeddings to
`null` rather than to a provider that fails on first use.

Before touching any Claude/Anthropic integration, load the `claude-api` skill.
Model IDs, pricing, and API shape drift; do not answer from memory.

### 3. Both plugin bootstraps must stay PHP 5.6-parseable

`storecrew.php`, `src/Core/Requirements.php`, `uninstall.php`, and
`storecrew-pro.php` load **before** the PHP version guard runs. PHP 8 syntax
there means a site on 7.4 gets a white screen instead of the notice explaining
why. No typed properties, return types, constructor promotion, `match`, `??`,
enums, or arrow functions in those four files. Everything else targets PHP 8.3.

### 4. WooCommerce data goes through CRUD, never post meta

`wc_get_product()`, not `get_post_meta()`. This is what keeps the declared HPOS
compatibility real (FR-CORE-02). Both HPOS and Cart/Checkout Blocks
compatibility are declared on `before_woocommerce_init`.

### 5. Only repositories touch `$wpdb`

Everything else goes through `src/Database/Repositories/`. This is what makes
the vector storage format swappable if the FR-KB-09 recall measurement forces an
external vector store — nothing outside `KnowledgeChunkRepository` knows
embeddings are packed float32 in a LONGBLOB.

### 6. The free plugin never knows premium exists

Premium adds capability **only** through the published extension API. If it
needs something not reachable from `ExtensionApi`, widen the API — never reach
across. `StoreCrew\` must contain zero references to `StoreCrew\Pro\`.

### 7. Retrieved content is untrusted input

It can never alter tool authorisation or agent routing.
`storecrew_tool_authorized` **may only deny, never grant** — its return is ANDed
with the decision already made in `ToolExecutor`. Authorisation derives from
session capabilities, never from model output. Retrieved text enters the prompt
as user-role content, never as system.

Three further invariants live in the agent layer, each probe-tested:

- An agent's `tool_ids` is an allow-list checked *before* the executor, so a
  tool an agent never declared cannot reach the security boundary at all.
- A merchant-edited persona cannot strip guardrails — they are appended after it.
- Identity verification proves *one* identity. `order.lookup` refuses any order
  other than the one confirmed.

### 8. Inside wp-admin, layered CSS always loses

Cascade layers are consulted **before** specificity: an unlayered declaration
beats a layered one at any specificity. WordPress admin CSS is unlayered, so a
plain `@import 'tailwindcss'` — which puts every utility in `@layer utilities` —
loses to `body { color }`, `a { color }`, and all of `forms.css`.

`admin-app/src/styles/app.css` therefore imports utilities **unlayered and
important**, and does *not* import preflight (it would reset the admin menu and
toolbar too). The app's own reset is scoped to `#storecrew-root` at ID
specificity, which is what it takes to outrank `input[type='text']:focus`.

This failure is invisible in light mode — WordPress's dark-grey-on-white looks
like what you intended. **Verify theme changes in dark mode**, where the
surfaces flip and unstyled text does not follow.

The admin app uses **no `@wordpress/*` packages** and bundles its own React.
Core ships whichever version its release pins; depending on it means inheriting
every future core upgrade as an untested breaking change.

### 9. On the storefront, a uuid is an address and never a credential

Conversations are addressed publicly by uuid — it travels in URLs, screenshots
and support emails. Reading or writing one requires the **session token** the
server issued, which lives in an HttpOnly cookie and is stored only as
`sha256(token)`. An unknown uuid and an unowned one return the **same 404**, so
the API cannot be used to confirm that a conversation exists.

The public chat routes are the only unauthenticated routes in the plugin.
`public_access()` marks each one, so an open route is always a visible decision
rather than a forgotten `permission_callback`.

Two things follow that are easy to undo by accident:

- **The storefront page carries no nonce and no conversation state.** Woo stores
  are page-cached; anything printed into the document is served to the next
  thousand visitors. `Widget` prints one `async` script tag and the REST root.
  Everything else comes from `/chat/boot`, which is never cached.
- **History is rebuilt from the database on every turn.** The widget posts one
  message, never a transcript. A client that could supply history could plant an
  assistant turn claiming identity was verified.

The widget renders model output with `createTextNode` / `createElement` only —
**never `innerHTML`**. That text was written by a model that has been reading
indexed product descriptions and customer reviews.

### 10. Never bundle an HTTP client

Use the WordPress HTTP API. Shipping Guzzle in a `.org` plugin collides with
every other plugin shipping a different Guzzle, and `wp_remote_post` honours the
proxy constants and `http_request_args` filters locked-down hosts rely on.

---

## Layout

```
storecrew.php              Bootstrap + guards        (PHP 5.6-parseable)
uninstall.php              Opt-in destruction        (PHP 5.6-parseable)
src/
  Plugin.php               Kernel: container, registration window, hooks
  Core/
    Requirements.php       Version guard             (PHP 5.6-parseable)
    Onboarding.php         The five setup steps, all derived
    Container/             Hand-written PSR-11, no autowiring
    Capabilities/          storecrew_manage, _view_analytics, _manage_agents, _converse
    Activation/            Activator / Deactivator
    Queue/                 Scheduler, Deadline, MaintenanceJob
  Api/
    ExtensionApi.php       The entire add-on contract
    Feature.php            Gateable feature + tier
    AdminRoute.php         SPA route declaration
    Registry/              Freezable registries
    Rest/                  RestController base + 9 controllers, storecrew/v1
  Database/
    Tables.php             The only place table names are built
    Migrator.php           Forward-only, locked, admin_init
    Migrations/
    Repositories/          10 repositories, the only $wpdb consumers
  Ai/
    Providers/             Anthropic, OpenAI, Gemini, OpenRouter, DeepSeek
    Http/                  HttpClientInterface + WP HTTP API implementation
    ModelPolicy, Pricing, SpendGuard, Capabilities, value objects
  Knowledge/
    Extractor/             Product, Post, keyset pagination trait
    Chunker, Indexer, Retriever, Vector, SourceSelection
    Jobs/                  IndexJob, EmbedJob, ReindexJob
  Agent/
    Agent, AgentRunner, AgentTurn, Orchestrator, TurnBudget, SharedContext
    Tool/                  ToolInterface, ToolExecutor (the security boundary)
    Tools/                 product.search, policy.lookup, identity.verify,
                           order.lookup, order.note, agent.handoff
  Chat/
    ChatService            One customer turn, end to end
    Session                Session token: issue, digest, cookie
    RateLimiter            Per session and per IP (FR-CHAT-06)
    ChatSettings           Widget appearance and placement
    Widget                 Async enqueue, shortcode, block
  Licensing/FeatureGate.php
  Security/SecretStore.php Envelope encryption, rotatable
docs/                      Architecture deliverables
tests/                     See below
```

## The registration window

Deterministic, and everything downstream depends on it:

| Priority | What happens |
|---|---|
| `plugins_loaded` 5 | Free plugin boots the kernel |
| `plugins_loaded` 6 | Premium handshakes, then hooks `storecrew_api_ready` |
| `plugins_loaded` 10 | `storecrew_api_ready` fires; registry filters apply |
| `plugins_loaded` 20 | Registries **freeze** |

Writing to a frozen registry throws under `WP_DEBUG` and is logged in
production. Add-ons must register on `storecrew_api_ready`.

Registries: features, admin routes, providers, extractors, REST controllers,
tools, agents.

**Controllers, tools, and job handlers are lazy.** Controllers are stored as factories
resolved at `rest_api_init`; job handlers resolve from the container when the
job runs. Registering either eagerly builds every repository on every request —
a storefront page load must not pay to construct an API it will never serve.
This has been reintroduced three times; the integration harness catches it
because it runs with no database at all.

---

## Testing

Ten PHP suites (757 assertions) plus two browser suites (40 assertions),
green in any run order.

```bash
# Browser: cascade fights and cookie/cache behaviour — invisible to PHP.
# Admin spec needs STORECREW_TEST_USER / STORECREW_TEST_PASS (skips loudly
# without); STORECREW_TEST_LIVE=1 opts into the one token-spending section.
npm run test:browser

# Static analysis: phpcs (WPCS, tuned) + phpstan (level 5) + the invariant
# checker (noGlobalWpdb + carve-outs, noProReferenceInFree, parse-safety —
# each self-testing via --self-test) + the DB-free harness.
composer check
```

**Suites must not touch what they cannot restore.** Options are
snapshot-and-restored — and that is not enough on its own: while a suite's
fake model policy is live, a real corpus's vectors read as *mismatched*, and
an unscoped `embed_pending()` re-embeds the merchant's index with fake
vectors (found via a "0 of 67 ready" board on a configured store). Embedding
calls in suites pass their own source ids; `verify-knowledge`'s cleanup
probes that no fake vector survives.

`verify-rest.php` needs `--user=1`: it dispatches through the real REST
server, and its permission probes deliberately start unauthenticated.
`verify-chat.php` takes it for the same reason and then drops to user 0 for the
storefront probes — **run those as an administrator and they prove nothing**,
because a signed-in customer legitimately reclaims their own conversation from
any device, so "a stranger" who is really the same logged-in user passes every
ownership check.

```bash
# Real MySQL / real WooCommerce / real Action Scheduler / real WP_REST_Server
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-agents.php --user=1
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-rest.php --user=1
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-schema.php
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-repositories.php
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-providers.php
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-knowledge.php
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-jobs.php
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-admin.php --user=1
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-chat.php --user=1

# The injection corpus (12 § 10, R-SEC-02). The compliant-scripted driver runs
# here with no key and proves every attack dies at a boundary. Add
# STORECREW_ADVERSARIAL_LIVE=1 to also run the corpus against the store's live
# model — it asserts no breach and reports which attacks reached the boundary;
# a free-tier 429 is a safe non-exercise, not a failure.
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-adversarial.php --user=1

# No database, no WordPress — boots both plugins against a hook shim
./tests/integration/run.sh
```

Suites clean up after themselves and are safe to re-run.

**Probe-test every guard.** A rule that has never been observed to fire is not a
rule. Deliberately violate it once and assert it fires — the suites do this for
frozen registries, duplicate ids, circular container dependencies, tampered
ciphertext, ragged embedding vectors, and the migration lock.

`wp eval-file` runs files through `eval()`, so test files **cannot** carry
`declare(strict_types=1)`.

---

## Bugs already found and fixed — do not reintroduce

| Bug | Why it hid |
|---|---|
| `ids()` fetched the first N and filtered after | Cursor never advanced; a full index stopped silently after ~20 objects. Keyset pagination must reach the database (`posts_where`). |
| `Scheduler::cancel()` with a group | Action Scheduler only takes its cancel-by-hook fast path with **no** group; passing one matches args exactly, missing every job carrying an id. |
| `LEXICAL_FLOOR = 3` | A query matching 1–2 chunks is *precise*, not failed. Treating it as failure triggered the full dense scan the two-stage design exists to avoid. |
| Eager job-handler resolution | Built every repository on every request; broke the DB-free harness. |
| Eager REST controller construction | Same root cause, found the same way. Controllers are factories now. |
| Eager tool construction | Third time. Tools are factories now. Anything depending on repositories must be lazy. |
| Tailwind utilities beaten by wp-admin | Layer order outranks specificity, and WordPress is unlayered. Light mode looked fine because WP's grey-on-white resembled the intent; only dark mode exposed it. |
| Tailwind preflight shipped globally | Reset `*` and `button` across the whole admin page, not just the app root. |
| `health()` reported a ready index with no model | `count_embedded('')` means "don't filter by model" — right for counting rows, wrong for "is this searchable". Showed *62 of 62 ready* on an install that could answer nothing. |
| `AdminPage` behind `is_admin()` | Gated a gate — `admin_menu` is admin-only anyway — and hid the menu from WP-CLI, the only harness that could test it. |
| `index_object()` never marked sources indexed | Sources sat at `pending` forever; the dashboard would show an index that never finishes. |
| Gemini `thoughtSignature` not replayed | Tool calls executed, then the continuation turn 400'd. Only a live call finds this. |
| Providers depending on concrete `HttpClient` | Request shaping was untestable without network. |
| Widget Markdown classified a whole block as list-or-paragraph | "Here is what I can tell you:" then three dashed lines is the shape models actually produce, and it fell to the paragraph branch and printed literal hyphens. Group lines into runs. |
| `null === ( $x['k'] ?? 'fallback' )` in a probe | `??` treats a **null value** as absent, so the assertion could never pass whatever the endpoint returned. Use `array_key_exists` when null is the expected value. |
| Ownership probes run as an administrator | A signed-in customer may reclaim their own conversation from any device, so a "stranger" who is the same logged-in user passes every check. Storefront probes must run as user 0. |
| `verify-repositories` poisoned FULLTEXT stats for its own future runs | InnoDB keeps deleted rows in the FULLTEXT index — and its term statistics — until OPTIMIZE. Each run left three ghost docs full of the probe's own search terms; IDF decayed until the lexical probe stopped ranking its chunk first, dozens of runs after the cause. Cleanup now OPTIMIZEs the table. Any suite that inserts-and-deletes FULLTEXT rows needs the same. |
| `verify-providers` cleanup deleted the merchant's **real** provider keys | The forget-list included `provider.gemini.key` etc. with no snapshot — every run silently unconfigured a configured store. Invisible on a keyless dev site; the secrets edition of the wipe-the-model-policy bug. Cleanup now snapshots `storecrew_secrets` **and** `storecrew_data_key` (rotation replaces the key the ciphertexts need) and restores both. |
| "Unconfigured store" probes assumed the state instead of constructing it | verify-rest's canEmbed/degraded probes and verify-jobs' blocked-embedding probe only ever passed because another suite had just wiped the keys — and on a configured store, the jobs probe ran the embed job against the merchant's **live key**, a billable call from inside a test. Probes that need an unconfigured store now hide the keys for their duration and restore. |
| A test probe deselected a source type against the merchant's live index | `POST /index/sources` purges what falls out of scope — correct behaviour, catastrophic as a probe. Selecting `['post']` inside `verify-rest` deleted 47 real product chunks on a configured store; the suite restored the *option* and never noticed the rows were gone. The purge is now probed in `verify-knowledge` against a **synthetic source type**, where the only rows at risk are the ones the probe created. Any probe of a destructive endpoint needs its own fixture, not the merchant's data. |
| A fatal mid-suite left the merchant carrying the suite's fake model policy | `verify-knowledge` writes the live policy at the top and restores it at the bottom; a fatal in between (a constructor signature change) skipped the restore, and the *next* run snapshotted the poison and put it back. Snapshot-and-restore is not enough on its own — the restore is registered with `register_shutdown_function` now, so it survives the fatal that makes it necessary. |
| Boot minted its nonce as user 0, killing the widget for every signed-in visitor | The boot request carries the login cookie but no nonce, so core demotes it to anonymous *before* the handler runs; the user-0 nonce is then refused (`403 rest_cookie_invalid_nonce`, before any callback) on every POST, which arrives with the same cookie and verifies as the signed-in user. Absent nonce degrades to guest; **wrong** nonce refuses. Invisible to curl, the PHP suites, and a fresh browser — all anonymous. boot now mints the nonce for the user `wp_validate_auth_cookie` proves. |

---

## Conventions

- **Commits** state *why*, not *what*. Subject reads as a sentence, no
  `feat:`/`fix:` prefixes. Body explains the reasoning and records bugs found.
- **Pricing that is unknown reports unknown**, never zero. A fabricated figure
  produces a spend cap that never trips while the merchant believes they are
  protected.
- **No silent caps.** If something truncates, log it —
  `storecrew_retrieval_truncated` exists for exactly this.
- Verify with the suites above. `wp-cli` is available; the site runs
  WordPress 7.0.3 / PHP 8.4.7 / WooCommerce 11.0.0.

---

## Known gaps

- **FR-KB-09 has been measured** — see `tools/measure-recall.php`. recall@3 is
  0.96 on a 62-chunk corpus with dense weight 1.0. Two findings carried into the
  design: the lexical arm *hurts* ranking (0.80 vs 1.00), and the two-stage
  prefilter is only used above 2,000 chunks where a full scan is too slow.
  **Large-corpus recall is still unmeasured and expected to be worse** — that
  case needs the external vector index R-TECH-01 named.
- ~~SKU lookup~~ — `product.lookup` (2026-08-07) resolves identifiers exactly;
  the recall harness scores identifier fixtures against it, semantic path
  unconsulted.
- **Streaming is built** (FR-CHAT-02, 2026-08-07): `StreamingChatProviderInterface`
  (additive, Gemini implements it), `CurlSseClient` (the one sanctioned raw-cURL
  site, proxy constants honoured), SSE negotiation by Accept header after every
  guard, and widget token rendering with a single whole-message screen-reader
  announcement. 22 probes; live `event: delta` frames observed on the wire; the
  provider-failure path exercised live *through* the SSE transport (customer got
  a sentence in `done`, conversation escalated). Two live findings on record:
  Gemini separates SSE events with `\r\n\r\n` (parser normalises), and the
  free tier meters `streamGenerateContent` separately from `generateContent` —
  a key can chat but not stream. **Timed incremental delivery is verified
  live** (2026-08-08): `php tools/probe-streaming-delivery.php` — real HTTP,
  per-chunk timestamps, self-judging verdict, cleans up its own conversation —
  observed 9 deltas at 9 distinct network arrivals over 609 ms, reassembling
  exactly to the `done` payload, through nginx + php-fpm and every guard. It
  took eight 429'd attempts across two keys to catch a quota window: the free
  tier's `generate_content_free_tier_requests` bucket (limit 20) is per-model
  and behaves far tighter than its "retry in Ns" hint — routing on 3.5-flash
  kept answering while 3.6-flash chat refused, and each refusal re-exercised
  the failure path end-to-end. **Remaining:** only the buffering-host half of
  R-TECH-02, which belongs to the budget-host validation row.
- ~~No failover execution~~ — done (2026-08-07): one switch to the configured
  fallback mid-turn, continuing from the request state so executed tools never
  re-run; both attempts on the run record.
- `Pro\Licence` is a **stub** — local option, no remote validation, no grace
  period. Not a security boundary; must not ship as-is.
- **The storefront chat surface is live-verified** (21 REST routes). Five turns
  through the widget against real Gemini: routing picked Sales then Support,
  `product.search` and `policy.lookup` grounded the answers, a wrong email did
  not verify, and the right one verified and read the real order **in one turn**
  (two tool calls in one run — the mid-turn identity listener). The site's
  working policy: `gemini-3.6-flash` chat, `gemini-3.1-flash-lite` routing,
  `gemini-embedding-001` embeddings.
- **Gemini's 2.5 generation is dead for new keys** — 404 "no longer available to
  new users" — and free-tier keys have zero quota for the Pro tiers (429). Both
  were found live on 2026-08-07; neither is findable from a test suite. When a
  merchant reports "chat says something went wrong", the run record's
  `error_code` distinguishes them.
- **Suites must snapshot-and-restore any live option they touch.** Four suites
  cleaned up with `delete_option` on the model policy and wiped a configured
  store's provider assignments every run — invisible until a site had actually
  been configured. The pattern is in each suite's head; keep it for any new
  option a test writes.
- The admin app has been verified in a real browser (Playwright: all seven
  screens, both themes, mobile, and a settings write round-trip). It has still
  never been seen with a *populated* inbox against live traffic.
- **The onboarding flow is built** (FR-ADMIN-02, 2026-08-08): one `/setup`
  screen, five steps, every control inline; **first activation redirects into
  it once** (`storecrew_setup_redirect`, set only when `storecrew_activated_at`
  was absent, consumed before anything is decided so it cannot loop —
  browser-verified firing from `plugins.php` and *not* firing on the next load);
  step state derived from the thing itself in `Core\Onboarding` and served on
  `/bootstrap`. The `index` step completes on **one vector, not a drained
  queue** — embedding scales with the catalogue and the 15-minute criterion is
  about the merchant's time; the remainder stays on screen in words. Two capabilities were
  missing under it and are now real — **source selection** (`POST
  /index/sources`; the walker, the estimate, and the live save hook all honour
  it, and deselecting purges rather than leaving excluded content quotable) and
  **agent activation** (`GET`/`POST /agents`, finally writing the
  `agent_configs.enabled` column the orchestrator has always read; `CrewBar`
  shows "Stood down" so the switch is visibly connected). **Remaining:** the
  exit criterion is a *measurement* — the five-step path completing in ≤ 15 min
  on a fresh install, timed on someone who is not us. Nothing in this repo can
  observe that.
- Model IDs and pricing are point-in-time (verified 2026-06-24) and will drift.
- **The four Gate 2 code defects are fixed and probe-tested** (2026-08-07,
  `docs/reviews/gate-2-review.md`): retrieval provenance now travels by the
  `storecrew_retrieval_performed` action into `agent_runs.retrieved`;
  `ToolExecutor` redacts identity-bearing arguments (the tool still sees raw
  values — verification depends on it); every `/chat/*` response is marked
  `no-store` via `rest_post_dispatch`; and the routing classifier is
  spend-guarded. Still open from that review: retention pruning beyond the
  audit log and the GDPR exporter/eraser are **planned, not built** (04 § 11
  now says so); no phpcs/phpstan config exists despite `composer.json`
  scripts; Pro has no `uninstall.php` though free's uninstall says it does;
  `storecrew_needs_upgrade` is written but never read.
- **The Gate 3 findings are remediated** (2026-08-07,
  `docs/reviews/gate-3-review.md`): handoff is wired via the `agent.handoff`
  tool and a run-scoped `storecrew_handoff_requested` listener (one hop per
  customer turn); surfaced products reach `SharedContext` via
  `storecrew_products_surfaced`; `cost_known` persists (Migration002 — the
  migration machinery's first real firing); refusals and provider failures
  are metered; invented-tool rows resolve to `failed`; run `error_code`
  carries the provider's own code ("429:RESOURCE_EXHAUSTED"); `get_json()`
  retries like POST; and regression probes exist for `thoughtSignature`
  replay and mid-turn identity. FR-AGENT-09 is rescoped: persona now,
  merchant guardrail overrides deferred (`agent_configs.guardrails` is
  stored but consumed by nothing — deliberate). **Gate 3 was approved
  2026-08-07** after re-verifying every fix against `src/` and re-running the
  suites in two orders (615 assertions each). Three surfaces stay stored and
  inert on purpose, now with owners: `agent_configs.guardrails` and
  `agent_configs.model_policy` in 14 § M1, `METRIC_CONVERSATION` in § M4 —
  a conversation cap counts nothing until that metric is written.
