# 15 — Free/Premium Split & Extension API

**Status:** Gate 1 approved — § 9 open questions resolved by 02 § 9 (D1–D10)
**Version:** 1.0
**Date:** 2026-08-07 (ratified 2026-08-07)
**Implements:** FR-DIST-01 … FR-DIST-12, FR-LIC-03, FR-CORE-10

---

## 1. Purpose

StoreCrew ships as two plugins. This document defines the boundary between them and the hook contract that connects them.

The governing rule:

> **The free plugin never knows premium exists. Premium adds capability only through hooks the free plugin publishes for everyone.**

If premium needs something the extension API does not expose, the fix is to *extend the API in the free plugin* — never to reach across the boundary. This keeps the free plugin honest, keeps the API real rather than theoretical, and means any third party can build what premium builds.

---

## 2. What Goes Where

### 2.1 Free plugin — `storecrew`

Owns the entire platform. It is a complete product, not a demo.

| Subsystem | Contents |
|---|---|
| Kernel | Bootstrap, PSR-11 container, module loader, activation/deactivation, migration runner |
| **Extension API** | Every registry and hook in §4. This is the free plugin's most important export |
| Agents | Agent framework, orchestrator, **Sales agent**, **Support agent** |
| Tools | Product search, catalogue read, order lookup (identity-verified), policy lookup, order notes |
| Knowledge base | Extractors, chunking, embeddings, hybrid retrieval, incremental re-index |
| Providers | OpenAI, Anthropic, Gemini, OpenRouter, DeepSeek |
| Chat | Storefront widget, SSE streaming, session handling, rate limiting |
| Admin | The **entire React SPA shell** — routing, layout, design system, auth, query client |
| REST | `storecrew/v1` namespace, controller registry, response envelope |
| Licensing | License client, entitlement resolution, capability manifest endpoint |
| Metering | Conversation counting, token accounting, free-tier enforcement |

### 2.2 Premium plugin — `storecrew-pro`

Contributes only through the API. Contains no copy of free-plugin code.

| Subsystem | Contents |
|---|---|
| Agents | **Marketing agent**, **Analytics agent**, custom agent builder |
| Tools | Coupon creation, segment building, campaign dispatch, forecasting queries |
| Workflows | Visual workflow builder — engine, node types, runner |
| Integrations | Mailchimp, Brevo, FluentCRM, Klaviyo, ActiveCampaign adapters |
| Admin | Additional SPA routes and panels, mounted into the free shell |
| Agency | Multi-site management, team roles, white-label branding |
| MCP | MCP client and external tool servers |
| Updates | Self-hosted, license-gated updater |

### 2.3 The line, restated

**Free** answers questions and resolves support. **Premium** runs campaigns, builds automations, and reports on the business.

Free is not a trial. A solo merchant should be able to run StoreCrew free indefinitely and get real value — that is what earns the WordPress.org install base that premium converts from.

---

## 3. Lifecycle & Handshake

### 3.1 Load order

Both plugins load on `plugins_loaded`. Ordering is made deterministic by priority, not by luck:

| Priority | Actor | Action |
|---|---|---|
| 5 | Free | Boot kernel, build container, register core modules |
| 10 | Free | Fire `storecrew_api_ready` — **the extension point** |
| 10 | Premium | Handshake, then register everything it contributes |
| 20 | Free | Freeze registries, resolve entitlements, register REST routes |

Premium **must** register on `storecrew_api_ready`, never earlier. Registries are frozen at priority 20; a late registration throws in development and is ignored with a logged warning in production.

### 3.2 Version handshake

The free plugin declares an **extension API version** independent of its product version. The API version changes only when the contract changes.

```
STORECREW_API_VERSION      = '1.0'      // contract version — semver major.minor
STORECREW_VERSION          = '1.0.0'    // product version
```

Premium declares the range it supports and refuses to run outside it:

- Free plugin missing or inactive → admin notice, register nothing, **no fatal** (FR-DIST-05)
- API version below premium's minimum → notice asking the merchant to update the free plugin
- API **major** version above premium's maximum → notice asking the merchant to update premium
- Match → proceed

The check runs before any `use` of a free-plugin class, so a version mismatch can never surface as a fatal "class not found."

### 3.3 Deactivation

| Event | Behaviour |
|---|---|
| Premium deactivated | Free continues fully. Premium tables and data **persist**. Premium-owned agents disappear from the UI; their conversation history remains readable |
| Free deactivated | Premium self-disables at the handshake and shows a notice. No fatal |
| Premium uninstalled | Data removed only if the merchant opts in, matching the free plugin's uninstall policy |
| License lapses | Premium code stays loaded; entitlement resolution returns `false` for gated features. Existing data stays readable and exportable (FR-LIC-06) |

---

## 4. Extension API

All hooks are prefixed `storecrew_`. Every registry follows the same shape: a filter receiving a registry object, returning it modified.

### 4.1 Registries

| Hook | Type | Purpose |
|---|---|---|
| `storecrew_api_ready` | action | Fired once when the container is built and registries are open. **The entry point for all extensions** |
| `storecrew_register_agents` | filter | Contribute agent definitions to the agent registry |
| `storecrew_register_tools` | filter | Contribute tools; each declares read/write intent, capability, and JSON schema |
| `storecrew_register_providers` | filter | Contribute AI provider implementations |
| `storecrew_register_extractors` | filter | Contribute knowledge-base source extractors |
| `storecrew_register_rest_controllers` | filter | Contribute REST controllers into `storecrew/v1` |
| `storecrew_register_admin_routes` | filter | Contribute SPA routes, menu entries, and panel mount points |
| `storecrew_register_container` | filter | Contribute service definitions to the PSR-11 container |
| `storecrew_register_migrations` | filter | Contribute owned database migrations |
| `storecrew_register_features` | filter | Declare gateable feature slugs and their required entitlement |
| `storecrew_register_workflow_nodes` | filter | Contribute workflow node types (premium engine, but open to third parties) |

### 4.2 Behavioural filters

| Hook | Purpose |
|---|---|
| `storecrew_feature_enabled` | Final say on whether a feature slug is active. **Server-side authority** (FR-DIST-09) |
| `storecrew_capability_manifest` | The entitlement object serialised to the SPA |
| `storecrew_agent_system_prompt` | Adjust an agent's system prompt before dispatch |
| `storecrew_agent_route` | Override orchestrator routing for a turn |
| `storecrew_retrieval_results` | Post-process retrieved chunks before they enter the prompt |
| `storecrew_retrieval_query` | Rewrite a retrieval query |
| `storecrew_tool_authorized` | Veto a tool call. **May only deny, never grant** (see §6) |
| `storecrew_provider_request` | Mutate an outbound provider request |
| `storecrew_conversation_context` | Extend the structured shared context |
| `storecrew_widget_config` | Adjust storefront widget configuration |
| `storecrew_usage_limit` | Override metering limits |

### 4.3 Event actions

Fire-and-forget notifications. Extensions observe; they do not control flow.

| Hook | Fired when |
|---|---|
| `storecrew_conversation_started` | A new conversation opens |
| `storecrew_conversation_ended` | A conversation closes or times out |
| `storecrew_agent_run_completed` | An agent turn finishes — carries the full run record |
| `storecrew_tool_executed` | A tool completes — carries arguments and result |
| `storecrew_handoff` | Conversation transferred between agents |
| `storecrew_escalated` | Escalated to a human |
| `storecrew_index_completed` | A knowledge-base index run finishes |
| `storecrew_license_changed` | License state transitions |

---

## 5. Admin SPA Composition

The SPA lives entirely in the free plugin. Premium does not ship a second React application (FR-DIST-12) — that would double the bundle, split the design system, and force two build pipelines.

Instead:

1. Premium registers routes and panels through `storecrew_register_admin_routes`.
2. The free plugin serves a **capability manifest** at boot describing which features and routes are entitled.
3. Premium's UI code is a separate JS bundle, enqueued only when premium is active, that registers its components into a client-side extension registry exposed by the shell.
4. The shell renders premium routes when both the route is registered *and* the manifest entitles it.

Gating is server-authoritative. A user who edits the client manifest sees an empty panel and gets a `403` from the API — the route exists but the controller denies it. **Client-side gating is presentation only; every premium REST controller re-checks entitlement independently.**

---

## 6. Security Boundary

Three rules that hold regardless of what extensions do:

1. **`storecrew_tool_authorized` may only deny.** It cannot grant authorisation that capability checks refused. Authorisation derives from the session's WordPress capabilities and the tool's declared requirement — never from a filter return value alone, and never from model output (R-SEC-01).

2. **Retrieved content is untrusted input.** Text from products, reviews, or uploaded documents can never alter tool authorisation, agent routing, or system prompts. Extensions receive it already marked untrusted and must keep it that way.

3. **Premium re-checks entitlement at every REST controller.** The capability manifest is a UI hint. It is never the authority.

---

## 7. Enforcement

These constraints are enforced by static analysis during implementation, not left to review discipline:

| Rule | Enforces |
|---|---|
| `storecrew.noCrossPluginImports` | `StoreCrew\Pro\` may reference only classes explicitly marked `@api` in `StoreCrew\`. Any other cross-namespace reference fails the build |
| `storecrew.noProReferenceInFree` | `StoreCrew\` must contain zero references to `StoreCrew\Pro\`, the `storecrew-pro` slug, or premium feature slugs outside the upsell-copy allowlist |
| `storecrew.apiVersionDocumented` | Any change to a hook signature in the `@api` surface requires a matching `STORECREW_API_VERSION` bump |
| `storecrew.noGlobalWpdb` | Database access goes through repositories |
| `storecrew.domainPurity` | No WordPress functions in the domain layer |

Every one of these rules must be **probe-tested** — deliberately violated once to confirm it fires — before being trusted. A clean run on a compliant codebase is not evidence that a rule works.

---

## 8. WordPress.org Compliance Notes

Constraints the free plugin must satisfy for directory acceptance:

- No premium code shipped disabled or obfuscated (FR-DIST-01)
- No loading of executable code from a remote server
- Upsell prompts permitted, but must not disable working functionality to create them (FR-DIST-10)
- No telemetry without explicit opt-in
- License and update calls originate from the **premium** plugin only — the free plugin makes no calls to Decent Themes infrastructure
- Readme, assets, and trademark usage must not imply an official WooCommerce or Automattic affiliation

**Open item.** The plugin's display name and slug relate to WooCommerce; the directory rejects names leading with a third-party trademark. `storecrew` does not, so submission is expected to clear — but the readme must describe StoreCrew as *"for WooCommerce"* rather than using WooCommerce as a name prefix. Confirmed as a copy constraint at Gate 5.

---

## 9. Open Questions for Gate 1 Review

**All resolved at the Gate 1 review, 2026-08-07** — decisions recorded in
[02-product-strategy.md](02-product-strategy.md) § 9. Kept here with their
resolutions because downstream documents cite these by number.

1. **Is the Sales agent free?** Currently yes, per the master brief's "Basic Sales Agent" on the free tier. But Sales is the clearest revenue driver — placing it behind Pro would convert harder while making free markedly less compelling. Needs an explicit decision, since it moves code between plugins.
   **Resolved (D2):** Sales stays free, exactly as built. Sales is the demo; the conversion driver is showing Sales and selling its amplification, not withholding it.
2. **Does the free tier meter conversations or agents?** PRD open question 1. This determines whether metering lives in free (it must, if free enforces a cap) and how premium lifts the cap.
   **Resolved (D1):** conversations, 100/month, tunable configuration. Metering lives in free; premium lifts the cap through entitlements (§ 9.2's design stands).
3. **Single premium plugin, or Pro + Agency as separate add-ons?** Current design is one premium plugin with tiered entitlements. Splitting Agency into a third plugin is possible but triples the release surface.
   **Resolved (D5):** one premium plugin, tiered entitlements. Revisit only if Agency-only code grows past roughly a third of the premium tree.
4. **Do third parties get the workflow node API in v1**, or is it premium-internal until stable?
   **Resolved (D6):** premium-internal. The node API is published once it has survived one minor version unchanged.
