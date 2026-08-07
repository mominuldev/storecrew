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
      Controllers/             9 controllers — registered as factories
  Database/
    Tables.php                 The only place table names are built
    Migrator.php               Forward-only, locked, admin_init
    MigrationInterface.php     One forward-only change: version(), up(),
                               deliberately no down()
    Migrations/                Migration001InitialSchema,
                               Migration002RunCostKnown
    Repository.php             Abstract base — where $wpdb actually lives
                               (injected, global fallback) and the 65,535-byte
                               JSON cap (encode_json)
    Repositories/              10 repositories extending it — the only $wpdb
                               consumers (carve-outs: principle 1 above)
  Ai/
    ProviderInterface          id / label / capabilities — the identity base
                               both halves extend; the split below is intact
    ChatProviderInterface, EmbeddingProviderInterface   (separate: 03 § 5)
    ChatRequest/Response, EmbeddingRequest/Response, Message,
    ToolCall, ToolDefinition, TokenUsage, Capabilities  (value objects)
    ModelPolicy, Pricing, SpendGuard
    Exception/                 ProviderException
    Http/                      HttpClientInterface + WP HTTP implementation
    Providers/                 Six files: Anthropic, OpenAi, Gemini, OpenRouter,
                               DeepSeek, and OpenAiCompatibleProvider — the
                               abstract base for the OpenAI chat-completions
                               shape (OpenAi, OpenRouter, DeepSeek differ by
                               base URL and headers, not by copied code)
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
                               order.lookup, order.note, agent.handoff
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

languages/                     Text domain: storecrew. Not in the repo — .org
                               installs get translations from
                               translate.wordpress.org; this directory exists
                               for self-hosted/local .mo files (the Domain Path
                               header and load_plugin_textdomain point here)
                               and appears when the first one lands
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

Current tree (the Phase 1 skeleton that proves the seam):

```
storecrew-pro.php              Bootstrap + handshake         (PHP 5.6-parseable)
composer.json / composer.lock  PSR-4: StoreCrew\Pro\ — vendor/ here genuinely
                               is autoloader-only, not even psr/container
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
| Repositories-only `$wpdb` (three carve-outs — principle 1) | 03 § 4, 04 § 1 |
| Four parse-safe files | FR-CORE version guard UX |
| Pro references nothing outside `Api\` | FR-DIST-02, 15 § 7 |
| Separate widget build | FR-CHAT-01 budget |
| assets/blocks exception | FR-ADMIN-01 (the SPA stays @wordpress-free) |
| tests/ structure | 03 § 12 harness separation |
