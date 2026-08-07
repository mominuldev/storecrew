# StoreCrew AI — Architecture Documentation

**The AI Employee Platform for WooCommerce.**
A commercial WordPress plugin by **Decent Themes**.

> **No implementation begins until all 14 deliverables are reviewed and approved.**

---

## Distribution Model

StoreCrew ships as **two plugins**. Decision recorded 2026-08-07.

| | Free | Premium |
|---|---|---|
| Product name | StoreCrew AI | StoreCrew AI Pro |
| Distribution | WordPress.org plugin directory | Decent Themes, license-keyed |
| Role | **Complete, standalone product.** Owns the platform, the extension API, and the free-tier agents | **Add-on.** Requires the free plugin. Registers additional agents, tools, providers, and UI through the published extension API |
| Updates | WordPress.org | Self-hosted update server, license-gated |

The premium plugin adds capability **only through the documented hook surface** — it never edits, patches, or reaches into free-plugin internals. See [15-free-premium-split.md](15-free-premium-split.md).

---

## Locked Identifiers

Agreed 2026-08-07. These propagate through every subsequent document and all code.

### Free plugin

| Concern | Value |
|---|---|
| Product name | StoreCrew AI |
| Short name (UI, docs) | StoreCrew |
| WordPress.org slug | `storecrew` |
| Directory | `wp-content/plugins/storecrew/` |
| Main plugin file | `storecrew.php` |
| PHP namespace | `StoreCrew\` |
| Text domain | `storecrew` |
| Database table prefix | `wp_scr_` |
| REST namespace | `storecrew/v1` |
| CSS / JS prefix | `scr-` |
| Option prefix | `storecrew_` |
| Hook prefix | `storecrew_` |
| Composer package | `decent-themes/storecrew` |

### Premium plugin

| Concern | Value |
|---|---|
| Product name | StoreCrew AI Pro |
| Slug | `storecrew-pro` |
| Directory | `wp-content/plugins/storecrew-pro/` |
| Main plugin file | `storecrew-pro.php` |
| PHP namespace | `StoreCrew\Pro\` |
| Text domain | `storecrew-pro` |
| Database table prefix | `wp_scr_` *(shared — distinct table names)* |
| REST namespace | `storecrew/v1` *(shared — routes registered via the extension API)* |
| CSS / JS prefix | `scr-` *(shared)* |
| Option prefix | `storecrew_pro_` |
| Hook prefix | `storecrew_pro_` |
| Composer package | `decent-themes/storecrew-pro` |

**Rationale for the shared REST namespace and asset prefix:** the admin SPA is a single application served by the free plugin. Forcing Pro onto a second REST namespace would split the client's API surface and require the SPA to know which plugin owns each route. Route *ownership* is tracked in the controller registry instead.

### Name Verification — Recorded Risks

The name was selected by the product owner with the following screening results on record. **These are accepted risks, not open questions.**

| Check | Result |
|---|---|
| WordPress.org plugin slug `storecrew` | ✅ Free — 0 plugins |
| Exact-match domain `storecrew.com` | ⚠️ **Held by a live e-commerce site** |
| Category crowding | ⚠️ Dense `Store*` cluster: StoreClerk (InfinitAI), StoreClaw, Storee (XRC Ventures), StoreDNA, StorePilot |
| Formal trademark clearance | ❌ **Not performed** — USPTO/EUIPO Class 9 + 42 search recommended before commercial launch |

Mitigation carried into [01-prd.md](01-prd.md) § Risks as **R-BRAND-01**. The launch domain must differ from `storecrew.com`; candidates to be selected during Gate 5.

---

## Deliverable Status

### Gate 1 — Product & Strategy
| # | Document | Status |
|---|---|---|
| 1 | [Product Requirements Document](01-prd.md) | ✅ Draft complete |
| 15 | [Free/Premium Split & Extension API](15-free-premium-split.md) | ✅ Draft complete |
| 2 | [Product Strategy](02-product-strategy.md) | ✅ Draft complete |

**Gate 1:** 🟡 All drafts complete — awaiting review. Deliverable 02 § 9 lists
the ten decisions (D1–D10) the review must ratify; it consolidates the open
questions from 01 § 13 and 15 § 9 with recommendations.

> Deliverable 15 is an addition to the original 14, requested 2026-08-07. It sits in Gate 1 because the freemium split is a commercial packaging decision before it is a technical one — it determines what is sold, and therefore what each plugin may contain.

### Gate 2 — Technical Architecture
| # | Document | Status |
|---|---|---|
| 3 | [Technical Architecture](03-technical-architecture.md) | ✅ Draft complete |
| 4 | [Database Schema](04-database-schema.md) | ✅ Draft complete |
| 5 | [REST API Specification](05-api-specification.md) | ✅ Draft complete |
| 7 | [Plugin Folder Structure](07-folder-structure.md) | ✅ Draft complete — covers **both** plugin trees |

**Gate 2:** 🟡 All drafts complete — awaiting review (after Gate 1).
Deliverables 03/05/07 document the **built and verified** system rather than a
proposal; each records where implementation experience amended the original
intent (e.g. the measured retrieval findings in 03 § 6, the two spikes A3/A4
partially resolved by measurement).

### Gate 3 — AI & Agent Core
| # | Document | Status |
|---|---|---|
| 8 | [Agent Framework Architecture](08-agent-framework.md) | ✅ Draft complete |
| 9 | [AI Provider Architecture](09-ai-provider-architecture.md) | ✅ Draft complete |

**Gate 3:** 🟡 All drafts complete — awaiting review (after Gate 2). Both
documents describe built, probe-tested subsystems; each closes with its known
gaps (streaming, failover execution) rather than leaving them implicit.

### Gate 4 — Application, UX & Commercial
| # | Document | Status |
|---|---|---|
| 6 | [React Application Structure](06-react-app-structure.md) | ✅ Draft complete |
| 11 | [UI/UX Wireframes](11-wireframes.md) | ✅ Draft complete — as-built |
| 10 | [SaaS Subscription Architecture](10-saas-subscription.md) | ✅ Draft complete — **design ahead of code**; consumes strategy D1–D5 |

**Gate 4:** 🟡 All drafts complete — awaiting review (after Gate 3). 06 and 11
document built, browser-verified surfaces; 10 is the one forward-looking
design in the set and says so, with its build order and the ship-blocking
licence-stub replacement called out.

### Gate 5 — Engineering Readiness
| # | Document | Status |
|---|---|---|
| 12 | [Security Architecture](12-security-architecture.md) | ⬜ Not started |
| 13 | [Scalability Plan](13-scalability-plan.md) | ⬜ Not started |
| 14 | [Development Milestone Plan](14-milestone-plan.md) | ⬜ Not started |

**Gate 5:** ⬜ Blocked by Gate 4

---

## Working Agreement

1. Documents are written in gate order. A gate is not started until the prior gate is approved.
2. Every functional requirement carries a stable ID (`FR-<AREA>-<NN>`). Downstream documents and code reference these IDs; they are never renumbered.
3. Architectural constraints that must be *enforced* (not merely documented) are listed in each document under **Enforcement**, and become static-analysis rules during implementation.
4. Any guard rule added during implementation must be **probe-tested** — deliberately violated once to confirm it fires. A clean run is not evidence a rule works.

---

## Relationship to Hiveclerk

StoreCrew AI is a **separate product** from Hiveclerk, also by Decent Themes. Decision recorded 2026-08-07.

- **No shared code.** Separate repository, separate namespace, separate database tables, independent release cycle.
- **Different market.** Hiveclerk targets general WordPress sites; StoreCrew targets WooCommerce merchants specifically.
- Prior art from Hiveclerk may inform design decisions, but nothing is inherited by reference. Where a Hiveclerk lesson is adopted, this documentation states the reasoning independently so these documents stand alone.
