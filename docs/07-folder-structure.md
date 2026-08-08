# 07 — Plugin Folder Structure

**Product:** StoreCrew AI (both plugin trees)
**Status:** Gate 2 approved — documents the trees as they exist, verified
**Version:** 1.0

Two principles decide where a file lives:

1. **Placement encodes the dependency rule.** `Database/Repositories/` is the
   only directory allowed to touch `$wpdb`; `Ai/Providers/` the only one that
   speaks a provider dialect; `Agent/Tool/` (singular) is the security
   boundary while `Agent/Tools/` (plural) is the things it authorises. A
   reviewer can see a boundary violation in a diff's *paths*. The `$wpdb` rule
   carries three deliberate carve-outs, recorded here so a grep does not
   falsify it: `Tables.php` (the naming authority needs the live prefix, and
   its existence check queries `SHOW TABLES`), the initial migration (needs
   `get_charset_collate()`), and — the one outside `Database/` —
   `Knowledge/Extractor/PagesPostTypeIds.php`, which uses `$wpdb->prepare()`
   inside a `posts_where` filter for keyset pagination: it augments a
   WP_Query, it never queries a plugin table. **The rule is automated**:
   `tools/check-invariants.php` (run by `composer check`) enforces it with
   exactly these carve-outs, plus `noProReferenceInFree` and the parse-safety
   rule below — and self-tests by violating each rule once, per the working
   agreement.
2. **Parse-safety is a location property.** Four files must stay
   PHP 5.6-parseable because they load before the version guard runs:
   `storecrew.php`, `uninstall.php`, `src/Core/Requirements.php`, and
   `storecrew-pro.php`. Everything else targets PHP 8.3. A typed property in
   any of them white-screens a PHP 7.4 site instead of showing the
   requirements notice — so `check-invariants.php` token-scans the free
   plugin's three for post-5.6 constructs (comments and strings stripped
   first; prose legitimately contains `??`).

---

## 1. Free plugin — `wp-content/plugins/storecrew/`

```
storecrew.php                  Bootstrap + guards            (PHP 5.6-parseable)
uninstall.php                  Opt-in destruction only       (PHP 5.6-parseable)
composer.json / composer.lock  PSR-4 autoload; one shipped dependency,
                               psr/container ^2.0 (interfaces only)
package.json / package-lock.json  Admin SPA + widget builds
.gitignore                     vendor/, node_modules/, build outputs, *.mo
vite.config.ts                 Admin SPA build  → assets/admin/
vite.widget.config.ts          Widget build     → assets/widget/  (separate on
                               purpose: opposite size constraints — see 03 § 10)
tsconfig.json                  Covers admin-app/ and widget-app/

docs/                          These deliverables; docs/README.md is the index
CHANGELOG.md                   Development milestones, reasoning recorded
CLAUDE.md                      Session orientation — read before touching code

src/
  Plugin.php                   Kernel: container defs, registration window,
                               hooks. Deliberately thin (03 § 3)
  Core/
    Requirements.php           Version guard                 (PHP 5.6-parseable)
    Onboarding.php             The five setup steps, every one derived from
                               the thing itself — never a stored "done" flag
    SetupProgress.php          When each step was first *observed* complete;
                               feeds the beta metrics, never the derivation
    Activation/                Activator, Deactivator
    Admin/                     AdminPage — menu + SPA mount, nothing else
    Capabilities/              storecrew_manage, _view_analytics,
                               _manage_agents, _converse
    Container/                 Hand-written PSR-11 + its two exceptions
    Privacy/                   PersonalData — core's exporter/eraser hooks
    Queue/                     Scheduler, Deadline, MaintenanceJob
  Api/                         The extension surface (Deliverable 15)
    ExtensionApi.php           The entire add-on contract
    Feature.php, AdminRoute.php
    Secrets.php                Published: put/get/has/forget, so an add-on
                               never invents its own credential storage
    Attribution.php            Published: which orders followed a
                               conversation — and methodology(), written by
                               the code that records the links
    Registry/                  Registry base + 7 freezable registries
    Rest/
      RestController.php       Envelope, deny-by-default permission()
      Controllers/             9 controllers — registered as factories
  Database/
    Tables.php                 The only place table names are built
    Migrator.php               Forward-only, locked, admin_init
    MigrationInterface.php     One forward-only change: version(), up(),
                               deliberately no down()
    Migrations/                001 InitialSchema, 002 RunCostKnown,
                               003 DropUpgradeFlag, 004 DropVersionOption,
                               005 Attributions
    Repository.php             Abstract base — where $wpdb actually lives
                               (injected, global fallback) and the 65,535-byte
                               JSON cap (encode_json)
    Repositories/              11 repositories extending it — the only $wpdb
                               consumers (carve-outs: principle 1 above)
  Ai/
    ProviderInterface          id / label / capabilities — the identity base
                               both halves extend; the split below is intact
    ChatProviderInterface, EmbeddingProviderInterface   (separate: 03 § 5)
    StreamingChatProviderInterface   Additive, never a change: stream()
                               returns the same assembled response chat()
                               would, so every runner decision reads the
                               assembly (FR-CHAT-02)
    ChatRequest/Response, EmbeddingRequest/Response, Message,
    ToolCall, ToolDefinition, TokenUsage, Capabilities  (value objects)
    ModelPolicy, Pricing, SpendGuard
    Exception/                 ProviderException
    Http/                      HttpClientInterface + WP HTTP implementation;
                               SseClientInterface + CurlSseClient, the one
                               sanctioned raw-cURL site (wp_remote_post
                               buffers by design), proxy constants honoured
    Providers/                 Six files: Anthropic, OpenAi, Gemini, OpenRouter,
                               DeepSeek, and OpenAiCompatibleProvider — the
                               abstract base for the OpenAI chat-completions
                               shape (OpenAi, OpenRouter, DeepSeek differ by
                               base URL and headers, not by copied code)
  Knowledge/
    ExtractorInterface, ExtractedDocument, Chunker, Indexer,
    Retriever, Vector
    SourceSelection.php        Which source types the merchant indexes.
                               Deselecting purges — excluded content that is
                               still quotable is the failure this prevents
    Extractor/                 ProductExtractor, PostExtractor,
                               PagesPostTypeIds (keyset pagination)
    Jobs/                      IndexJob, EmbedJob, ReindexJob
  Agent/
    Agent, CoreAgents, AgentRunner, AgentTurn, Orchestrator,
    SharedContext, TurnBudget
    Tool/                      The boundary: ToolInterface, ToolExecutor,
                               ToolContext, ToolResult
    Tools/                     product.search, product.lookup, policy.lookup,
                               identity.verify, order.lookup, order.note,
                               agent.handoff
  Chat/                        The storefront surface (03 § 8) and the
                               merchant-side console (08 § 6b)
    ChatService, Session, RateLimiter, ChatSettings, Widget
    ConsoleService             One merchant turn, on the `console` channel —
                               no routing, no identity, no escalation, and no
                               conversation quota (the free-tier unit is a
                               *customer* conversation)
    OrderAttribution           Records which conversation preceded an order,
                               at checkout, because that is the only moment
                               the session cookie is still visible
    EscalationNotifier         One email per escalation — the transition
                               rings, further failed turns do not
    SseEmitter                 The `delta` / `done` event stream
  Licensing/
    FeatureGate.php            Server-authoritative entitlement; no network
    Quota.php                  Free-tier conversation cap; the
                               `storecrew_quota` filter is loosen-only
  Security/
    SecretStore.php            Envelope encryption, rotatable

admin-app/src/                 React SPA source (no @wordpress/* anywhere)
  main.tsx, App.tsx
  components/                  Layout, CrewBar, primitives
  lib/                         api, store, types
  pages/                       Overview, Crew, Knowledge, Inbox,
                               ConversationDetail, Settings, Setup
  styles/app.css               Unlayered-important utilities; #storecrew-root
                               scoped reset (03 § 10)

widget-app/src/                Storefront widget source (no framework)
  main.ts                      Boot, shadow mount, accent contrast
  widget.ts                    Panel, focus trap, a11y
  sse.ts                       Transport-agnostic SSE assembler, extracted so
                               the browser suite drives the *shipping* parser
                               under buffered / streamed / byte-split / CRLF
                               delivery (R-TECH-02)
  api.ts, render.ts, types.ts, widget.css

assets/                        Build outputs + hand-written editor script
  admin/                       app.js, app.css        (built, committed)
  widget/                      widget.js              (built, committed)
  blocks/chat-block.js         Hand-written against wp.* globals — the one
                               file that may assume Gutenberg, because it runs
                               only inside the editor

tests/
  schema/                      11 wp-eval-file suites (real MySQL/Woo/REST).
                               No declare(strict_types) — eval() forbids it.
                               Must snapshot-and-restore any live option they
                               write, and must **construct** the state they
                               assert rather than inheriting the merchant's —
                               an admin id, a source selection, an approval
                               queue and a licence tier have each been
                               assumed, and each failure read as a defect in
                               the subject rather than in the fixture (03 § 12)
  browser/                     3 Playwright/node specs — cascade fights,
                               cookie and cache behaviour, and the SSE
                               assembler; invisible to PHP
  integration/                 run.sh, wp-shim.php, test-boot.php,
                               test-guards.php — no WP, no DB; catches eager
                               construction and undeclared WP calls

tools/                         Operator/measurement scripts, not shipped:
                               build-dist.sh (the .org submission gate),
                               check-invariants.php, measure-recall.php
                               (FR-KB-09 harness), probe-budget-host.php
                               (R-TECH-03), probe-streaming-delivery.php
                               (timed incremental delivery),
                               beta-metrics.php, seed-demo-catalogue.php,
                               phpstan-bootstrap.php

languages/                     Text domain: storecrew. Holds storecrew.pot
                               (the generated catalogue translators work
                               from); .org installs get translations from
                               translate.wordpress.org, and self-hosted .mo
                               files land here too — the Domain Path header
                               and load_plugin_textdomain point at it
vendor/                        Composer autoloader plus the psr/container
                               interfaces the hand-written container implements
                               — no runtime code libraries, no HTTP client
                               (03 § 5)
node_modules/                  Never shipped; .org build excludes it with
                               admin-app/, widget-app/, tests/, tools/, docs/,
                               package-lock.json, composer.lock, .gitignore
```

### What is deliberately absent

- **No `includes/` grab-bag.** Every file has a layer.
- **No bundled HTTP client, no polyfills** in `vendor/`.
- **No shared library with Hiveclerk** (docs/README § relationship) — nothing
  inherited by reference.
- **`src/` contains zero references to `StoreCrew\Pro\`** — enforced as
  `storecrew.noProReferenceInFree` (15 § 7).

---

## 2. Premium plugin — `wp-content/plugins/storecrew-pro/`

Current tree. Every directory maps to a registry the free plugin exposes,
which is the point (15 § 2.2):

```
storecrew-pro.php              Bootstrap + handshake         (PHP 5.6-parseable)
                               and the Update URI header (FR-DIST-08)
composer.json / composer.lock  PSR-4: StoreCrew\Pro\ — vendor/ here genuinely
                               is autoloader-only, not even psr/container
uninstall.php                  Exactly Pro's licence options, nothing the free
                               plugin owns
phpcs.xml, phpstan.neon        Its own, matching free's
CHANGELOG.md, CLAUDE.md
src/
  Plugin.php                   Handshake (FR-DIST-04/05), registers on
                               storecrew_api_ready only
  Licence.php                  Facade over Licensing\LicenceClient — a tier
                               and a status word, which is all anything
                               downstream ever asked the stub for. Never reads
                               a bare option, never talks to the network
  Licensing/                   Snapshot + LicenceClient (Ed25519-verified
                               entitlement envelopes, verified locally, with
                               grace and site binding) + Updater.
                               ⚠ LicenceClient::PUBLIC_KEY is empty until the
                               licence server exists (10 § 6.1) — fail-closed
                               as status `unconfigured`, and ship-blocking
  Agent/                       MarketingAgent, AnalyticsAgent — declarations,
                               not subclasses; both AUDIENCE_ADMIN
    Tools/                     coupon.create, segment.build, segment.sync,
                               metrics.report, product.performance,
                               revenue.attribution
  Analytics/                   MetricsQuery
  Marketing/                   SegmentQuery
  Integrations/                EspAdapter + EspRegistry + BaseEspAdapter,
                               ConsentSource; Mailchimp and FluentCRM
                               adapters (Brevo, Klaviyo, ActiveCampaign
                               still to come)
  Rest/                        4 controllers contributed through the free
                               plugin's registry — Licence, Marketing,
                               Analytics, Integrations
assets/                        Built panels mounted into the free shell — no
                               second application (FR-DIST-12)
languages/, tools/
```

Still absent, and deliberately: `Workflows/` (FR-MKT workflow builder) and
`Agency/` (multi-site, roles, white-label) — both Agency-tier, both unstarted.

> ⚠ Pro has **no `.distignore`**, so nothing yet excludes `tools/` or `tests/`
> from a Pro build the way `.distignore` does for the free plugin. Anything
> placed in Pro's `tools/` — a local dev-licence stub, say — is a candidate
> for shipping until that file exists.

Structural rules restated from 15: no file in `storecrew-pro` may reference a
free-plugin class outside `StoreCrew\Api\` and the value objects it exposes;
premium tables use the shared `wp_scr_` prefix with distinct names; premium
never registers anything before `storecrew_api_ready`.

---

## 3. Build & Release Surfaces

| Artefact | Source | Command | Budget |
|---|---|---|---|
| `assets/admin/app.js` | `admin-app/` | `npm run build:admin` | ≤ 250 KB gz (at 105 KB) |
| `assets/widget/widget.js` | `widget-app/` | `npm run build:widget` | ≤ 45 KB gz (at 6.0 KB) |
| .org zip | everything minus dev dirs | `tools/build-dist.sh` | — |

`build-dist.sh` is the submission gate as a command: `.distignore` applied, the
front end rebuilt from current source, a `--no-dev` vendor, `composer.lock`
removed after installing from it. It builds into a sibling directory and never
runs `--no-dev` against the working tree's `vendor/`, which would delete
phpstan and phpcs and break `composer check` with no obvious cause. Verify
Plugin Check against the *built dist*, never the working tree.

Built assets are committed so `wp eval-file` suites and a checkout-without-
node both work; the release build regenerates and verifies them.

---

## 4. Traceability

| Rule here | Enforces |
|---|---|
| Repositories-only `$wpdb` (three carve-outs — principle 1) | 03 § 4, 04 § 1 |
| Four parse-safe files | FR-CORE version guard UX |
| Pro references nothing outside `Api\` | FR-DIST-02, 15 § 7 |
| Separate widget build | FR-CHAT-01 budget |
| assets/blocks exception | FR-ADMIN-01 (the SPA stays @wordpress-free) |
| tests/ structure | 03 § 12 harness separation |
