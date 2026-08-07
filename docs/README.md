# StoreCrew AI — Architecture Documentation

**The AI Employee Platform for WooCommerce.**
A commercial WordPress plugin by **Decent Themes**.

> **All fifteen deliverables are approved at v1.0** (all five gates,
> 2026-08-07). The gate that governs work now is 14 § M1's exit-criteria
> list; a change that alters documented behaviour edits the document in the
> same change-set.

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
| 1 | [Product Requirements Document](01-prd.md) | ✅ Approved (v1.0) |
| 15 | [Free/Premium Split & Extension API](15-free-premium-split.md) | ✅ Approved (v1.0) |
| 2 | [Product Strategy](02-product-strategy.md) | ✅ Approved (v1.0) |

**Gate 1:** ✅ **Approved 2026-08-07.** All ten decisions (D1–D10) ratified as
recommended, without amendment — recorded in 02 § 9; the open questions in
01 § 13 and 15 § 9 carry their resolutions inline. No requirement changed:
D1 confirms FR-LIC-02, D7 confirms FR-SUPPORT-08.

> Deliverable 15 is an addition to the original 14, requested 2026-08-07. It sits in Gate 1 because the freemium split is a commercial packaging decision before it is a technical one — it determines what is sold, and therefore what each plugin may contain.

### Gate 2 — Technical Architecture
| # | Document | Status |
|---|---|---|
| 3 | [Technical Architecture](03-technical-architecture.md) | ✅ Approved (v1.0) |
| 4 | [Database Schema](04-database-schema.md) | ✅ Approved (v1.0) |
| 5 | [REST API Specification](05-api-specification.md) | ✅ Approved (v1.0) |
| 7 | [Plugin Folder Structure](07-folder-structure.md) | ✅ Approved (v1.0) — covers **both** plugin trees |

**Gate 2:** ✅ **Approved 2026-08-07** (reviewed, remediated, and approved the
same day). Four independent verification passes (docs vs code, live DB, live
route table) found the documents structurally accurate but surfaced four code
defects behind documented guarantees — all fixed and probe-tested the same
day (suites grew 566 → 583 assertions, all green), with the doc-stale
findings absorbed into 03/04/05/07. The approval accepted the review's
doc-side dispositions and moved its four open tickets (retention pruning,
GDPR exporter/eraser, static-analysis configs, Pro `uninstall.php`) into
14 § M1 as pre-launch exit criteria. Findings and outcomes:
[reviews/gate-2-review.md](reviews/gate-2-review.md).
Deliverables 03/05/07 document the **built and verified** system rather than a
proposal; each records where implementation experience amended the original
intent (e.g. the measured retrieval findings in 03 § 6, the two spikes A3/A4
partially resolved by measurement).

### Gate 3 — AI & Agent Core
| # | Document | Status |
|---|---|---|
| 8 | [Agent Framework Architecture](08-agent-framework.md) | ✅ Draft complete |
| 9 | [AI Provider Architecture](09-ai-provider-architecture.md) | ✅ Draft complete |

**Gate 3:** ✅ **Approved 2026-08-07** (reviewed, remediated, and approved the
same day). Two
verification passes found no security defects but surfaced a cluster of
capabilities documented as working that no production path exercised. All
nine code findings are fixed per the ratified decisions — handoff wired as
the `agent.handoff` tool, `cost_known` via Migration002 (the migration
machinery's first real firing), refusals metered, FR-AGENT-09 rescoped — and
the missing regression guards now exist. The remediation also caught the
test suites deleting the merchant's real provider keys and, once restored,
making a live billable call from a probe; both fixed. Suites: 583 → 615
assertions, green in shuffled order. The approval re-verified each fix
against `src/` and re-ran the suites in two orders, and moved the three
deliberately-dormant surfaces (`agent_configs.guardrails`,
`agent_configs.model_policy`, `METRIC_CONVERSATION`) into 14 § M1 and § M4 as
exit criteria. Findings and outcomes:
[reviews/gate-3-review.md](reviews/gate-3-review.md).

### Gate 4 — Application, UX & Commercial
| # | Document | Status |
|---|---|---|
| 6 | [React Application Structure](06-react-app-structure.md) | ✅ Approved (v1.0) |
| 11 | [UI/UX Wireframes](11-wireframes.md) | ✅ Approved (v1.0) — as-built |
| 10 | [SaaS Subscription Architecture](10-saas-subscription.md) | ✅ Approved (v1.0) — **design ahead of code**; consumes strategy D1–D5 |

**Gate 4:** ✅ **Approved 2026-08-07** (reviewed, decisions ratified, and
remediated the same day). The review found no security defect but seven code
findings and three specification defects — the recurring built-but-unconsumed
shape arriving as the whole capability manifest, plus 10's entitlement keys
matching no registered slug. First-pass remediation fixed the specification
defects, the unexplained approval conflict, and every doc-stale claim; the
four decisions G4-D1–D4 were then ratified as the findings argue and built:
the shell renders from the manifest (nav from routes, crew board from the
catalog — premium's screens reachable, premium's copy its own), arguments
render as a definition list, the browser suite is checked in
(`npm run test:browser`, 33 assertions), and the Inbox gained "Waiting for a
human" while the escalation email stays an M1 ticket. Verifying G4-D1 in a
live browser surfaced one further defect the suites were green over —
`verify-knowledge`'s fake provider had re-embedded the real corpus with
worthless vectors — fixed at three layers (scoped embedding, NULL-first
drain order, cleanup revert probe), and the fix exposed a probe that had
been vacuous since it was written. 616 suite + 33 browser assertions green;
corpus integrity verified across two consecutive runs. Findings and
outcomes: [reviews/gate-4-review.md](reviews/gate-4-review.md).

### Gate 5 — Engineering Readiness
| # | Document | Status |
|---|---|---|
| 12 | [Security Architecture](12-security-architecture.md) | ✅ Approved (v1.0) — threat-model-ordered, probe-referenced |
| 13 | [Scalability Plan](13-scalability-plan.md) | ✅ Approved (v1.0) — measured numbers, named cliffs |
| 14 | [Development Milestone Plan](14-milestone-plan.md) | ✅ Approved (v1.0) — exit-criteria-gated, no dates |

**Gate 5:** ✅ **Approved 2026-08-07** (reviewed and remediated the same
day). The first gate since 1 where the code survived its documents: every
probe 12 cites was traced to the suite that fires it, 13's figures to the
recall harness's recorded runs, and 14 to the Gate 2–4 tickets it carries.
Three wording defects, no code defects — 13 described transcript retention
as built when only audit pruning runs (the substrate-as-capability shape
again), 12 § 9's "paths exist" was readable as "retention is enforced" in
the one document compliance answers get copied from, and 12 § 2's
"disjoint allow-lists" lapsed when Gate 3 deliberately shared the handoff
tool. All fixed; findings:
[reviews/gate-5-review.md](reviews/gate-5-review.md).

---

## All fifteen deliverables are approved (2026-08-07)

The set was completed *after* most of the system was built, and the documents
say so where it matters: 03–09, 06, 11–13 describe verified reality and record
where implementation amended intent; 10 designs ahead of code and opens by
saying so; 02 § 9 carries the ten decisions (D1–D10), ratified at Gate 1.
Every gate was reviewed against the code rather than by reading — the review
records live in [reviews/](reviews/), including what each review found, what
was fixed, and what moved into 14 § M1 with an exit criterion. The pattern
every gate confirmed, worth carrying forward: the recurring defect class is
**built-but-unconsumed** (something shipped that nothing reads), and the
recurring documentation defect is **substrate reported as capability**. A
future change should be checked against both shapes before it is called done.

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
