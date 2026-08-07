# 07 — Plugin Folder Structure

**Product:** StoreCrew AI (both plugin trees)
**Status:** Draft complete — documents the trees as they exist, 2026-08-07
**Version:** 0.1

Two principles decide where a file lives:

1. **Placement encodes the dependency rule.** `Database/Repositories/` is the
   only directory allowed to touch `$wpdb`; `Ai/Providers/` the only one that
   speaks a provider dialect; `Agent/Tool/` (singular) is the security
   boundary while `Agent/Tools/` (plural) is the things it authorises. A
   reviewer can see a boundary violation in a diff's *paths*.
2. **Parse-safety is a location property.** Four files must stay
   PHP 5.6-parseable because they load before the version guard runs:
   `storecrew.php`, `uninstall.php`, `src/Core/Requirements.php`, and
   `storecrew-pro.php`. Everything else targets PHP 8.3. The rule is keyed to
   these paths and checked by review — a typed property in any of them white-
   screens a PHP 7.4 site instead of showing the requirements notice.

---

## 1. Free plugin — `wp-content/plugins/storecrew/`

```
storecrew.php                  Bootstrap + guards            (PHP 5.6-parseable)
uninstall.php                  Opt-in destruction only       (PHP 5.6-parseable)
composer.json / composer.lock  PSR-4 autoload; no runtime deps shipped
package.json                   Admin SPA + widget builds
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
    Activation/                Activator, Deactivator
    Admin/                     AdminPage — menu + SPA mount, nothing else
    Capabilities/              storecrew_manage, _view_analytics,
                               _manage_agents, _converse
    Container/                 Hand-written PSR-11 + its two exceptions
    Queue/                     Scheduler, Deadline, MaintenanceJob
  Api/                         The extension surface (Deliverable 15)
    ExtensionApi.php           The entire add-on contract
    Feature.php, AdminRoute.php
    Registry/                  Registry base + 7 freezable registries
    Rest/
      RestController.php       Envelope, deny-by-default permission()
      Controllers/             8 controllers — registered as factories
  Database/
    Tables.php                 The only place table names are built
    Migrator.php               Forward-only, locked, admin_init
    Migrations/                Migration001InitialSchema
    Repositories/              10 repositories — the only $wpdb consumers
  Ai/
    ChatProviderInterface, EmbeddingProviderInterface   (separate: 03 § 5)
    ChatRequest/Response, EmbeddingRequest/Response, Message,
    ToolCall, ToolDefinition, TokenUsage, Capabilities  (value objects)
    ModelPolicy, Pricing, SpendGuard
    Exception/                 ProviderException
    Http/                      HttpClientInterface + WP HTTP implementation
    Providers/                 Anthropic, OpenAi, Gemini, OpenRouter, DeepSeek
  Knowledge/
    ExtractorInterface, ExtractedDocument, Chunker, Indexer,
    Retriever, Vector
    Extractor/                 ProductExtractor, PostExtractor,
                               PagesPostTypeIds (keyset pagination)
    Jobs/                      IndexJob, EmbedJob, ReindexJob
  Agent/
    Agent, CoreAgents, AgentRunner, AgentTurn, Orchestrator,
    SharedContext, TurnBudget
    Tool/                      The boundary: ToolInterface, ToolExecutor,
                               ToolContext, ToolResult
    Tools/                     product.search, policy.lookup, identity.verify,
                               order.lookup, order.note
  Chat/                        The storefront surface (03 § 8)
    ChatService, Session, RateLimiter, ChatSettings, Widget
  Licensing/
    FeatureGate.php            Server-authoritative entitlement; no network
  Security/
    SecretStore.php            Envelope encryption, rotatable

admin-app/src/                 React SPA source (no @wordpress/* anywhere)
  main.tsx, App.tsx
  components/                  Layout, CrewBar, primitives
  lib/                         api, store, types
  pages/                       Overview, Crew, Knowledge, Inbox,
                               ConversationDetail, Settings
  styles/app.css               Unlayered-important utilities; #storecrew-root
                               scoped reset (03 § 10)

widget-app/src/                Storefront widget source (no framework)
  main.ts                      Boot, shadow mount, accent contrast
  widget.ts                    Panel, focus trap, a11y
  api.ts, render.ts, types.ts, widget.css

assets/                        Build outputs + hand-written editor script
  admin/                       app.js, app.css        (built, committed)
  widget/                      widget.js              (built, committed)
  blocks/chat-block.js         Hand-written against wp.* globals — the one
                               file that may assume Gutenberg, because it runs
                               only inside the editor

tests/
  schema/                      9 wp-eval-file suites (real MySQL/Woo/REST).
                               No declare(strict_types) — eval() forbids it.
                               Must snapshot-and-restore any live option they
                               write (03 § 12)
  integration/                 run.sh, wp-shim.php, test-boot.php,
                               test-guards.php — no WP, no DB; catches eager
                               construction and undeclared WP calls

tools/                         Operator/measurement scripts, not shipped:
                               measure-recall.php (FR-KB-09 harness),
                               seed-demo-catalogue.php

languages/                     Text domain: storecrew
vendor/                        Composer autoloader only — no runtime libraries
                               (no HTTP client: 03 § 5)
node_modules/                  Never shipped; .org build excludes with
                               admin-app/, widget-app/, tests/, tools/, docs/
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

Current tree (the Phase 1 skeleton that proves the seam):

```
storecrew-pro.php              Bootstrap + handshake         (PHP 5.6-parseable)
composer.json / composer.lock  PSR-4: StoreCrew\Pro\
CHANGELOG.md, CLAUDE.md
src/
  Plugin.php                   Handshake (FR-DIST-04/05), registers on
                               storecrew_api_ready only
  Licence.php                  ⚠ Stub — local option, no remote validation.
                               Must not ship as-is (CLAUDE.md known gaps)
```

Planned shape as Phase 2 lands (mirrors 15 § 2.2 — every directory maps to a
registry the free plugin exposes, which is the point):

```
src/
  Agents/                      Marketing, Analytics, custom-agent builder
  Tools/                       coupon.create, segment.build, campaign.send, …
  Workflows/                   Engine, node types, runner
  Integrations/                Mailchimp, Brevo, FluentCRM, Klaviyo,
                               ActiveCampaign adapters
  Admin/                       SPA route contributions (mounted into the free
                               shell — no second application, FR-DIST-12)
  Agency/                      Multi-site, roles, white-label
  Licensing/                   Real licence client + self-hosted updater
                               (FR-DIST-08)
admin-app/                     Builds contributed panels only
```

Structural rules restated from 15: no file in `storecrew-pro` may reference a
free-plugin class outside `StoreCrew\Api\` and the value objects it exposes;
premium tables use the shared `wp_scr_` prefix with distinct names; premium
never registers anything before `storecrew_api_ready`.

---

## 3. Build & Release Surfaces

| Artefact | Source | Command | Budget |
|---|---|---|---|
| `assets/admin/app.js` | `admin-app/` | `npm run build:admin` | ≤ 250 KB gz (at 94 KB) |
| `assets/widget/widget.js` | `widget-app/` | `npm run build:widget` | ≤ 45 KB gz (at 5.3 KB) |
| .org zip | everything minus dev dirs | release script (Deliverable 14) | — |

Built assets are committed so `wp eval-file` suites and a checkout-without-
node both work; the release build regenerates and verifies them.

---

## 4. Traceability

| Rule here | Enforces |
|---|---|
| Repositories-only `$wpdb` | 03 § 4, 04 § 1 |
| Four parse-safe files | FR-CORE version guard UX |
| Pro references nothing outside `Api\` | FR-DIST-02, 15 § 7 |
| Separate widget build | FR-CHAT-01 budget |
| assets/blocks exception | FR-ADMIN-01 (the SPA stays @wordpress-free) |
| tests/ structure | 03 § 12 harness separation |
