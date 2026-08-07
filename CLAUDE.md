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

Written so far: `01-prd.md`, `04-database-schema.md`,
`15-free-premium-split.md`. Still unwritten: 02 (Product Strategy), 03
(Technical Architecture), 05 (REST API Spec), 06–14.

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

### 8. Never bundle an HTTP client

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
    Container/             Hand-written PSR-11, no autowiring
    Capabilities/          storecrew_manage, _view_analytics, _manage_agents, _converse
    Activation/            Activator / Deactivator
    Queue/                 Scheduler, Deadline, MaintenanceJob
  Api/
    ExtensionApi.php       The entire add-on contract
    Feature.php            Gateable feature + tier
    AdminRoute.php         SPA route declaration
    Registry/              Freezable registries
    Rest/                  RestController base + 7 controllers, storecrew/v1
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
    Chunker, Indexer, Retriever, Vector
    Jobs/                  IndexJob, EmbedJob, ReindexJob
  Agent/
    Agent, AgentRunner, AgentTurn, Orchestrator, TurnBudget, SharedContext
    Tool/                  ToolInterface, ToolExecutor (the security boundary)
    Tools/                 product.search, policy.lookup, order.lookup, order.note
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

Seven suites, 446 assertions, green in any run order.

`verify-rest.php` needs `--user=1`: it dispatches through the real REST
server, and its permission probes deliberately start unauthenticated.

```bash
# Real MySQL / real WooCommerce / real Action Scheduler / real WP_REST_Server
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-agents.php --user=1
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-rest.php --user=1
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-schema.php
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-repositories.php
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-providers.php
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-knowledge.php
wp eval-file wp-content/plugins/storecrew/tests/schema/verify-jobs.php

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
| Providers depending on concrete `HttpClient` | Request shaping was untestable without network. |

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

- **FR-KB-09 recall has never been measured.** Both Gate 2 open questions
  (embedding precision; whether two-stage retrieval clears 0.88 recall) need a
  real catalogue, a real API key, and a fixture question set. The store
  currently has **0 products** and no provider key configured.
- No streaming. FR-CHAT-02 needs SSE, which `wp_remote_post` cannot do — it
  needs raw cURL with a write callback.
- No failover execution. `ModelPolicy::fallback()` resolves a target; nothing
  calls it yet.
- `Pro\Licence` is a **stub** — local option, no remote validation, no grace
  period. Not a security boundary; must not ship as-is.
- No admin SPA, and no chat surface. The REST API (18 routes) and the agent
  framework both exist, but nothing has run an agent against a real model — the
  suite drives a scripted provider.
- No streaming, so a turn returns all at once.
- Model IDs and pricing are point-in-time (verified 2026-06-24) and will drift.
