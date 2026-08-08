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

Four further invariants live in the agent layer, each probe-tested:

- An agent's `tool_ids` is an allow-list checked *before* the executor, so a
  tool an agent never declared cannot reach the security boundary at all.
- A merchant-edited persona cannot strip guardrails — they are appended after it.
- Identity verification proves *one* identity. `order.lookup` refuses any order
  other than the one confirmed.
- **An agent's `audience` decides who can reach it.** Routing, the classifier
  catalogue, and `agent.handoff`'s targets all read
  `Orchestrator::available_agents()`, which defaults to `storefront` — so a
  merchant-facing agent is unreachable from the widget and is only ever run by
  name through `converse()`. An unknown audience throws at construction rather
  than defaulting, because a misspelling falling back to `storefront` would
  fail in the direction that puts customer data in front of shoppers. Added
  2026-08-08 for premium's Marketing agent, which reads purchase history and
  creates coupons: registering it without this would have made it answerable to
  anyone who opened the chat widget. `ToolContext::is_storefront` derives from
  it too — both surfaces arrive over REST, where `is_admin()` is false either
  way, so the old derivation called every merchant turn a storefront turn.

### 7b. The storefront and the console never share a conversation

`ConsoleService` is the merchant-side counterpart to `ChatService`, on the
`console` channel (08 § 6b). The channel is part of the *lookup*, not just a
label: `find_open_for_session()`, `find_open_for_customer()`, and `recent()`
are channel-scoped, and `ChatService::authorise()` refuses any non-widget
conversation outright. A shop manager holds one WordPress user id on both
surfaces, so an unscoped lookup — or the FR-CHAT-05 cross-device path, which is
reached by uuid and keyed on that id — would hand the storefront widget the
merchant's own console thread and then answer it with a storefront agent.

The console consumes **no conversation quota**. The free-tier unit is a
*customer* conversation (FR-LIC-02); charging a merchant for asking their own
agent a question is the fabricated-figure rule pointed at the merchant. Tokens
and spend are still metered, because those are real. It also never escalates —
escalation summons a human, and the human is typing.

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
    Attribution.php        Published: which orders followed a conversation
    Feature.php            Gateable feature + tier
    AdminRoute.php         SPA route declaration
    Registry/              Freezable registries
    Rest/                  RestController base + 9 controllers, storecrew/v1
  Database/
    Tables.php             The only place table names are built
    Migrator.php           Forward-only, locked, admin_init
    Migrations/
    Repositories/          11 repositories, the only $wpdb consumers
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
    ConsoleService         One merchant turn, for admin-audience agents
    OrderAttribution       Records which conversation preceded an order
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

Eleven PHP suites plus three suites under `tests/browser`
(56 assertions), green in any run order.

```bash
# Browser: cascade fights and cookie/cache behaviour — invisible to PHP.
# Admin spec needs STORECREW_TEST_USER / STORECREW_TEST_PASS (skips loudly
# without); STORECREW_TEST_LIVE=1 opts into the one token-spending section.
# sse.spec.mjs needs neither a browser nor a site: it transpiles the shipping
# SSE assembler and proves buffered==streamed delivery (R-TECH-02).
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
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-attribution.php --user=1

# The injection corpus (12 § 10, R-SEC-02). The compliant-scripted driver runs
# here with no key and proves every attack dies at a boundary. Add
# STORECREW_ADVERSARIAL_LIVE=1 to also run the corpus against the store's live
# model — it asserts no breach and reports which attacks reached the boundary;
# a free-tier 429 is a safe non-exercise, not a failure.
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-adversarial.php --user=1

# No database, no WordPress — boots both plugins against a hook shim
./tests/integration/run.sh

# Budget-host validation (R-TECH-03). Prints the host capability report and runs
# a full index under a forced-tight kill window, proving resume-to-completion
# across ~150 kills with exact accounting. Self-judging, keyless, snapshot-
# restoring; run it on a real $5/mo host and check the report against reality.
wp eval-file wp-content/plugins/storecrew/tools/probe-budget-host.php
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
| A fatal mid-suite left the merchant carrying the suite's fake model policy | `verify-knowledge` writes the live policy at the top and restores it at the bottom; a fatal in between (a constructor signature change) skipped the restore, and the *next* run snapshotted the poison and put it back. The restore was then also registered with `register_shutdown_function` — which **does not do what it was believed to do** (measured 2026-08-08 by writing to a file from the callback): under `wp eval-file` a suite's shutdown function does *not* run after an uncaught Throwable, because WordPress registers its own fatal handler first and ends the request there. It **does** run on a clean finish and on the `exit(1)` a failing suite takes; plain PHP runs it in both cases, which is where the belief came from. **`set_exception_handler` is what actually works** — it catches the `ArgumentCountError` a constructor change raises — so a suite that writes live options installs all three: end-of-file call, shutdown, and exception handler. The handler must re-report the error itself (installing one suppresses PHP's own message) or the suite dies silently, which is worse. Nothing survives a *true* fatal — memory exhaustion, timeout — and that exposure remains. Probe-tested by injecting a throw after the policy write and asserting the merchant's policy came back. |
| `verify-adversarial` overwrote the merchant's model policy and never restored it | It calls `$policy->save()` with a scripted provider **once per corpus item**, against the live option, with no snapshot — the same shape as the `verify-providers` key wipe, found the same way. It hid better than any of them: `ModelPolicy::resolve()` falls back to `infer()` when the stored provider is not registered, and `scripted` never is outside that file, so chat kept answering on an inferred provider and nothing looked broken. **The store silently degraded from honouring a choice to guessing** — worse than an outage, because an outage gets reported. Found on this repo's own dev store, whose Gemini selection had been replaced by `{"chat":{"provider":"scripted"}}`. Cleanup now restores it three ways and **asserts** the merchant kept what they configured: "chat still works" was never evidence the option was intact. |
| A test suite deleted the site's administrator | `verify-repositories` did `$gdpr_user = (int) wp_insert_user( … )` with no `is_wp_error()` check. **`(int)` on an object is `1` in PHP 8** — with a warning nobody reads — and 1 is the administrator. The trigger was a crashed earlier run leaving a probe user holding the fixture email, so the next `wp_insert_user` returned a duplicate-email `WP_Error`; the suite then ran its erasure probes against user 1 and finished with `wp_delete_user( 1 )`. Same family as the `??` bug below: an unchecked conversion producing a *plausible* value instead of failing. Now checked three ways — `is_wp_error()`, a refusal to run against any id ≤ 1, and a guard at the delete itself — plus a leftover-probe-user sweep on entry, because restoring state on the way out is no use if the suite cannot start again. **Any suite that creates a WordPress user needs all four.** |
| A crashed suite could not be run again | `verify-knowledge` creates a product with a fixed SKU. A fatal between creation and cleanup leaves it behind, and WooCommerce then refuses every subsequent run outright — "Invalid or duplicated SKU" — so one crash cost every run after it until someone found the orphan by hand. Restoring options on the way out is not enough if the suite cannot *start* again; it now clears a leftover probe product before creating its own, and says so. |
| Boot minted its nonce as user 0, killing the widget for every signed-in visitor | The boot request carries the login cookie but no nonce, so core demotes it to anonymous *before* the handler runs; the user-0 nonce is then refused (`403 rest_cookie_invalid_nonce`, before any callback) on every POST, which arrives with the same cookie and verifies as the signed-in user. Absent nonce degrades to guest; **wrong** nonce refuses. Invisible to curl, the PHP suites, and a fresh browser — all anonymous. boot now mints the nonce for the user `wp_validate_auth_cookie` proves. |
| The index walk could make zero forward progress on a tight host | `Deadline::has_room_for(1.5)` stopped a batch *before* its first object. Fine at the ≥5s auto-detected budget, but a host whose real kill window is tighter (php-fpm `request_terminate_timeout`, now clampable via `storecrew_index_batch_seconds`) could stop at zero objects, write the same cursor, reschedule, and loop forever indexing nothing. Latent because local objects are fast and the budget floor is 5s; surfaced by `tools/probe-budget-host.php` forcing a 1s window. The first object of every batch now always runs. |
| A probe asserted the store's licence instead of the plugin's logic | `verify-rest` asserted `agent.marketing` was locked in the manifest — true on an unlicensed store, false on an entitled one, so **every real Pro customer running the suite would have seen it fail**. Split into two licence-independent claims: the manifest carries premium slugs as booleans (taken from the registry, not hard-coded), and an unentitled premium feature is denied — probed by detaching every `storecrew_feature_enabled` grant and asking a **fresh** `FeatureGate`, because the container's memoises per request and would replay the earlier answer. **A probe that asserts a fixed value for something the merchant configures is asserting their configuration, not your code.** |
| A suite asserted the *global* approval queue was empty | `verify-agents`' cleanup checked `approval_queue()` had no rows at all, so a merchant's genuinely-pending coupon approvals — the queue doing its job — read as the suite failing to clean up. It now counts only rows matching its own delete criteria. Third instance of the count-the-platform-instead-of-yourself shape. |
| A suite blamed the walker for a source selection it never set | `verify-jobs` indexes pages, and the walker honours the merchant's selection — so on a products-only store the walk correctly skipped them and the suite reported **"the walk reaches objects beyond the first batch"**, the assertion that names the catalogued keyset-pagination bug. The fixture's fault read as a regression in the subject. Its partner probe passed *vacuously*: "unchanged content does not queue an embedding pass" is trivially true for content that is out of scope. The suite now merges the post type into the merchant's selection for its own duration and restores three ways. **Restoring the option is not restoring the store** — `SourceSelection::save()` only reports what fell out of scope; the purge lives in `IndexController`, so cleanup must call `Indexer::forget_type()` itself or a products-only store keeps excluded-but-quotable rows. |
| A suite assumed the administrator was user 1 | `verify-rest` hard-coded `$admin_id = 1`, and reassigned to it internally *after* its deliberately-anonymous section — so `--user=` could not correct it. Once the administrator-deletion bug below had actually removed user 1, every authenticated probe 401'd: **82 failures that read as defects in the REST layer** rather than as a missing account. It now prefers the `--user=` caller when they hold `storecrew_manage`, falls back to any administrator, and exits loudly when there is none. The same suite creates a subscriber and had **none** of the four guards that incident put on `verify-repositories`, `wp_delete_user( (int) $sub_id )` included; it has all four now, and the entry sweep found a real leftover on its first run. **When a whole suite fails at once, suspect its fixtures before its subject.** |
| `max_tokens` sized for the answer, not the reasoning | It caps a reasoning model's thinking **and** its visible reply together, and current Anthropic models reason by default when the request says nothing about it. Both ceilings were chosen against Gemini, which does not. The routing classifier's `16` was correct arithmetic for an answer that is one identifier and enough for nothing at all on a model that thinks first — so the classifier returned empty, the empty string matched no agent id, and routing fell through to the default agent with no exception, no error code, and nothing in the run record. **A store that had silently stopped routing looked identical to one whose customers all happen to want the default agent.** Chat's `2048` had the milder version: enough for the answer, not the reasoning plus the answer, truncating mid-sentence. Both now come from `ModelPolicy::output_ceiling()` (routing 1024, chat 8192, 256 floor, `storecrew_max_output_tokens` filter), and a starved classifier fires `storecrew_routing_truncated` instead of defaulting quietly. Sized rather than removed — an unbounded ceiling is a merchant's bill with no upper edge. Not findable from the suites: every scripted model answers immediately. |
| A budget-host probe raced a cron-triggered Action Scheduler runner | Driving `IndexJob::run` in a loop enqueues each successor on the shared scheduler; a WP-Cron tick (which the probe's own loopback was spawning) fired the AS runner in another process, which ran the probe's run id through the *container's* real IndexJob — real catalogue, real selection — corrupting the accounting ~1 run in 6. The self-inflicted trigger (an active loopback in the capability report) was removed, and any probe driving jobs directly must detach the handlers and cancel each reschedule so a stray tick cannot claim its run. |

---

## Conventions

- **Commits** state *why*, not *what*. Subject reads as a sentence, no
  `feat:`/`fix:` prefixes. Body explains the reasoning and records bugs found.
- **Pricing that is unknown reports unknown**, never zero. A fabricated figure
  produces a spend cap that never trips while the merchant believes they are
  protected.
- **No silent caps.** If something truncates, log it —
  `storecrew_retrieval_truncated` exists for exactly this.
- **Escape exception messages** — `throw new X( esc_html( sprintf( … ) ) )`.
  Plugin Check's `EscapeOutput.ExceptionNotEscaped` flags every variable in a
  `throw`; the developer message gets `esc_html()`, but typed structured args
  (an HTTP status, a previous exception) must **not** be escaped — those get a
  justified `phpcs:ignore` (see `Ai\Http\HttpClient`/`CurlSseClient`, which
  disable the sniff file-wide because every throw there is provider metadata).
  The dist is **Plugin Check clean, 0/0**; keep it that way.
- **The .org dist ships less than the repo.** `.distignore` excludes `tests/`,
  `tools/`, `docs/`, `admin-app/`, `widget-app/`, `node_modules/`, and the
  static-analysis config; `composer install --no-dev` slims `vendor/` to the
  autoloader + `psr/container`. `readme.txt` is the .org-format readme (distinct
  from `CHANGELOG.md`). Verify against the *built dist*, not the working tree.
- Verify with the suites above. `wp-cli` is available; the site runs
  WordPress 7.0.3 / PHP 8.4.7 / WooCommerce 11.0.0.

---

## Known gaps

- ~~**Approval is recorded but never executed**~~ — **closed 2026-08-08**.
  `ToolExecutor::execute_approved()` is the second half of FR-AGENT-05, and
  `POST /approvals/{id}` now runs the write rather than stamping a row and
  reporting success. Four properties carry it, each probed: **approval is
  the claim** (`approve()`'s `required → pending` transition is the mutex, so
  a double-click, a double-submit, or two administrators cannot execute
  twice); **authorisation is re-derived, never replayed** (the agent's
  allow-list, its audience, the per-tool mode, the conversation's identity
  state, and the capability check all read live state — verification revoked
  since queueing revokes the write with it); **the capability checked is the
  approver's**, because they are the one taking responsibility; and a crash
  between claim and result leaves the row `approved` + `pending`, which
  `approve()` never matches again — a stuck row rather than a coupon issued
  twice. Verified live: the marketing agent was asked for a coupon, the write
  queued, `POST /approvals/{id}` returned it executed, and WooCommerce had
  the coupon with the right terms.
- **A queued write must be replayable exactly, so one cannot be queued at
  all.** Arguments are stored redacted (04 § 11), and executing a redacted
  argument later would carry out something *different* from what the merchant
  approved — an order note with the customer's email silently replaced by
  `[redacted]`. `ToolExecutor::execute()` therefore refuses to queue a write
  whose arguments redaction altered, tells the model to re-ask without the
  personal detail, and resolves the row so it never reaches the queue. This
  is a real constraint on write-tool design: **an approval-gated tool should
  take an id, not an email.**
- `tool_modes` is still stored by `AgentConfigRepository` with **no REST
  route and no UI**, so a merchant cannot set a tool to `auto` and skip the
  queue. With approval now executing, that is a convenience rather than the
  blocker it was.
- **A console turn cannot stream.** `Orchestrator::converse()` takes no
  `$on_delta`, and a segment scan over thousands of orders is exactly the turn
  a merchant would like to watch arrive. The SSE machinery exists on the
  storefront path; this is wiring, not design.
- **i18n is done for the customer-facing and server surfaces** (2026-08-08):
  user-facing PHP strings are `__()`-wrapped under `storecrew`, `languages/storecrew.pot`
  is generated, and the widget's own chrome (aria-labels, error messages) is
  translated server-side and delivered on the uncached `/chat/boot` response —
  the widget bundles no i18n runtime because it uses no `@wordpress/*` (rule 8).
  Two boundaries are deliberate, not oversights. **The admin SPA stays English**:
  translating it needs a server string catalog the no-`@wordpress/i18n` decision
  forces, deferred as non-blocking for beta. And **model-facing strings stay
  English** — tool descriptions and `ToolResult::error()` messages are read by
  the model, which answers in the *conversation's* language; wrapping them would
  push the merchant's locale into a conversation that may be in another language.
  The widget is RTL-safe (logical CSS + `dir` from `is_rtl()`), probed in
  `widget.spec.mjs`.
- **Revenue attribution is built** (FR-ANALYTICS-03, 2026-08-08). The
  linkage never existed — `conversations.verified_order_id` is identity
  verification pointing *backwards*, not attribution — so `scr_attributions`
  (04 § 3.3, Migration005) records it forwards, written by
  `Chat\OrderAttribution` on both checkout hooks and published to premium as
  `Api\Attribution`. Four things about it are load-bearing. **The table
  holds no money**: the row is a link and the amount is read live from the
  order, so a refund stops counting without anything noticing — FR-KB-08's
  discipline pointed at revenue. **`order_id` is unique**, which makes the
  model last-touch by construction and makes the doubled classic/Blocks
  checkout hook idempotent. **The methodology is published by the recorder**
  (`Api\Attribution::methodology()`) rather than restated by whatever
  reports on it, so the description cannot drift from the mechanism. And
  **the figure is a floor, never a total** — a shopper who chats on a phone
  and buys on a laptop is invisible — which the tool's summary states before
  it states the number. Links cascade with their conversation on both
  retention and GDPR erasure: a link to a conversation nobody can open is a
  revenue figure nobody can check.
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
  the failure path end-to-end. **Remaining (narrowed 2026-08-08):** the
  buffered==streamed *equivalence* is now probed — the SSE parse was extracted
  to `widget-app/src/sse.ts` and `tests/browser/sse.spec.mjs` drives the real
  code under buffered / streamed / byte-split / CRLF delivery, all reaching the
  same events (the assembler now also normalises CRLF/CR so a line-ending-
  rewriting proxy degrades to buffered, not broken). Only observing it on an
  actual buffering host is left, and that rides the budget-host real-host run.
- ~~No failover execution~~ — done (2026-08-07): one switch to the configured
  fallback mid-turn, continuing from the request state so executed tools never
  re-run; both attempts on the run record.
- ~~`Pro\Licence` is a stub~~ — **replaced** (2026-08-08):
  `Pro\Licensing\Snapshot` + `LicenceClient` verify Ed25519-signed
  entitlement envelopes locally, with grace, site binding, and
  grant-from-entitlements-map; probe-tested against fixture-signed
  envelopes in the integration harness. **Still ship-blocking:**
  `LicenceClient::PUBLIC_KEY` is empty until the licence server exists
  (10 § 6.1 is its contract) — fail-closed as status `unconfigured`. The
  activation UI exists (2026-08-08): a Licence tab on the Settings screen
  (moved there from a `/licence` route the same day), the first consumer of
  the shell's DOM-mount registries and the `storecrew_admin_assets` action
  (06 § 2.3). The updater's client half
  exists too (`Pro\Licensing\Updater` + the `Update URI` header, FR-DIST-08);
  only the server side of the spine remains.
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
  scripts; Pro has no `uninstall.php` though free's uninstall says it does.
  ~~`storecrew_needs_upgrade` is written but never read~~ — **removed
  2026-08-08** (Migration003): write, delete, and the rows already stored.
  It was the wrong trigger anyway (absent on the FTP-upgrade path that has
  migrations pending) and it leaked — the delete lived in `Migrator::run()`,
  which only executes when there *is* pending work, so re-activating an
  already-current site left an autoloaded row nothing would ever clear.
  `storecrew_version` went the same way (Migration004, found while fixing the
  first): activation was the only writer, so on the ordinary .org/FTP upgrade
  it reported the version the merchant ran *before* the update. **Activation
  now records no version at all** — `STORECREW_VERSION` is the running
  version and cannot go stale, and "what changed since last time" is the
  migrator's job, keyed on `storecrew_schema_version`.
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
  suites in two orders (615 assertions each). Three surfaces stayed stored
  and inert on purpose, with owners: `agent_configs.guardrails` and
  `agent_configs.model_policy` in 14 § M1; `METRIC_CONVERSATION` was § M4's
  and is **now written** (2026-08-08, M4.1's first change-set) — recorded on
  a conversation's first agent *answer* via `record_conversation()`
  (idempotent; failed turns charge nothing), read by `Licensing\Quota`
  (free 100/month, loosen-only `storecrew_quota` filter) and enforced at
  `/chat/session` only — resume and in-progress sends are never cap-gated.
  Probe-tested in `verify-chat`, which holds quota unlimited for its own run
  so an at-capacity store's suite stays honest.
