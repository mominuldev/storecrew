# 14 — Development Milestone Plan

**Product:** StoreCrew AI
**Status:** Draft complete — 2026-08-07
**Version:** 0.1

This plan starts from where the code actually is, which is far past where a
Gate 5 plan usually starts: the platform, agents, knowledge base, REST API,
admin console, and storefront chat all exist, are suite-verified (616 PHP +
33 browser
assertions after the Gate 2 and Gate 3 remediations), and have carried a live
five-turn conversation against a real model. The plan is therefore mostly a
**completion and hardening ledger for
Phase 1**, then the Phase 2 build that Pro launches on.

Sequencing rule inherited from strategy D9: *beta → .org free launch → Pro
at 500 installs / 4.5★ → Agency on demand signal.* Dates are deliberately
absent — milestones gate on exit criteria, not calendar; the working
agreement's gate discipline applies to code milestones exactly as it does to
documents.

---

## M0 — Done (for the record)

Platform kernel + extension API + registration window · schema + migrations
+ repositories · five providers + policy/pricing/spend/secrets · knowledge
pipeline with measured retrieval (FR-KB-09 first pass: recall@3 0.96) ·
agent framework + security boundary + identity · admin SPA (six screens,
browser-verified) · storefront chat surface (live-verified) · nine PHP suites, the checked-in browser suites,
and the DB-free harness · docs 01–15 drafted.

---

## M1 — Phase 1 complete ("nothing left that embarrasses a merchant")

The gap list, from CLAUDE.md, 03 § 13, and the Gate 2–5 reviews
(`docs/reviews/`), each with its exit criterion:

| Item | Exit criterion |
|---|---|
| ~~**Retention pruning (04 § 11)**~~ | ✅ **Done 2026-08-07.** All four windows enforced from the hourly sweep, batched; conversation pruning cascades; pending approvals exempt (probed); 04 § 11 flipped to Implemented |
| ~~**GDPR exporter/eraser (04 § 11)**~~ | ✅ **Done 2026-08-07.** Registered with the personal-data hooks, lazily resolved; erasure severs customer/order/session links and blanks content while counters survive; export excludes operator notes; 21 probes in `verify-repositories` |
| **Static-analysis configs (03 § 4)** | `composer lint` / `analyse` / `test` run green from the configs they reference; the repositories-only `$wpdb` rule is automated with its three documented carve-outs |
| **Pro `uninstall.php` (FR-DIST-06)** | Pro removes its own options on uninstall, making free's `uninstall.php` comment true; covered by the integration harness |
| **Streaming (FR-CHAT-02)** — raw-cURL SSE transport, `stream()` on the chat interface, widget token rendering, buffered fallback per host detection (R-TECH-02) | A streamed turn on a real host *and* the documented fallback exercised on a buffering host; 12 § 10's constraint holds (transport changed, authority untouched) |
| **SKU / exact-identifier tool** | "SKU-1042" answers from the identifier tool; the semantic path never sees it; fixture added to the recall harness |
| **Failover execution (FR-AI: `ModelPolicy::fallback()`)** | A provider 5xx mid-conversation completes the turn on the fallback; run record shows both attempts |
| **Merchant guardrail overrides (FR-AGENT-09, deferred half)** — `agent_configs.guardrails` is written and decoded by the repository and consumed by nothing; the storage shipped without the design | A merchant rule is composed into the prompt at a decided position relative to the shipped guardrails, and a probe proves an override cannot *loosen* one (R-SEC-01 holds against a hostile override, as it already does against a hostile persona); 01's FR-AGENT-09 rescope note and 08 § 8 both retire |
| **Per-agent model policy** — `agent_configs.model_policy` is stored and decoded the same way, read by nothing; `ModelPolicy` resolves globally | Either a per-agent override resolves ahead of the global policy with a probe covering both paths, or the column is documented as reserved in 04 and 08 § 1 — a stored-but-inert config surface is the shape both Gate 2 and Gate 3 caught, and it does not survive a third gate unlabelled |
| **Onboarding flow (FR-ADMIN-02)** | The five-step path (key → sources → index → agents → widget) completes on a fresh install in ≤ 15 min (the PRD's time-to-value target), measured on someone who is not us |
| ~~**Escalation notification**~~ | ✅ **Done 2026-08-07.** One email per escalation (the transition, not each failed turn — probed), linking into the inspector; the customer's words are never forwarded by mail; recipient filterable, empty disables |
| **Adversarial suite v2 (12 § 10)** | Injection corpus (hostile reviews/pages/notes) runs against a live model in CI-able form; every attempt dies at a boundary probe, not at model discretion |
| **Budget-host validation (R-TECH-03)** | Full index + a day's simulated chat on a $5/mo shared host; capability report matches reality |
| **i18n pass** | All strings translatable; `languages/` builds; RTL smoke test on the widget |
| **.org compliance pass** | Plugin-check clean; readme.txt; assets; GPL headers; the no-egress audit (12 § 9) documented for review |

Exit: all above green **and** the full suite + integration harness + both
browser verifications pass on WP current and current-1, Woo current, PHP 8.3.

## M2 — Private beta

20–30 stores from the Decent Themes base. Instrumented for the strategy's
leading indicators (02 § 7): onboarding step drop-off, deflection rate,
escalation reasons. Exit: two consecutive weeks with **zero
boundary-violation incidents** and **zero storefront fatals** across the
fleet; deflection ≥ 40% (launch target is 55% at 12 months, not at beta);
ten quotable merchants.

## M3 — WordPress.org launch (free)

Submission, review remediation, launch content (the job-phrase SEO set,
02 § 5.2). Post-launch: the strategy's 90-day tripwire is armed — flat
installs + setup-difficulty reviews moves the hosted-proxy decision up
(D8). Exit for M4 to begin: **500 active installs, ≥ 4.5★** (D9), support
load ≤ the PRD's 0.4 tickets/customer/month equivalent.

## M4 — Pro build & launch (Phase 2)

Order chosen so the licence spine lands first and each agent ships with its
tools' approval defaults:

1. **Licence infrastructure** (10 § 8): server + webhook + `LicenceClient`
   replacing the stub (**ship-blocking**), snapshot verification in
   `FeatureGate`, update server, free-tier cap enforcement at the widget.
   Includes the metering substrate FR-LIC-02 rests on:
   `UsageRepository::METRIC_CONVERSATION` is declared and recorded nowhere
   (Gate 3), so a conversation cap today counts nothing — a cap must be
   enforced against a metric that is actually written, and probe-tested at
   the boundary before any tier depends on it.
2. **Marketing agent** (FR-MKT): segments from Woo data, coupon tool
   (approval-gated — FR-SALES-06/FR-MKT-03), abandoned-cart sequence with
   consent discipline (FR-MKT-06 is a MUST before any send).
3. **Analytics agent** (FR-ANALYTICS): constrained query surface — never
   free-form SQL (FR-ANALYTICS-02); every figure traceable
   (FR-ANALYTICS-06).
4. **ESP adapters** (FR-MKT-04) behind one interface; ship with two
   (Mailchimp + FluentCRM — one hosted, one in-WordPress), the rest by
   demand.
5. **Workflow engine v1** (FR-WF, MAY-priority: only if beta demand
   confirms) — node API stays premium-internal (D6).
6. Exchange workflow completion (FR-SUPPORT-06) and refund-preparation
   (FR-SUPPORT-08) — Support's Phase 2 depth.

Exit: Pro on sale via the store, updates flowing, first 50 customers, NRR
instrumentation live.

## M5 — Agency (Phase 3, on demand signal)

Multi-site licence management (FR-LIC-04), reskin white-label (D4/5.5),
partner onboarding kit. Entry condition, not date: ≥ 20 organic agency
installs observed (D9). The hosted-proxy decision (D8) is reviewed here
with M3/M4 data regardless of the tripwire.

---

## Standing Obligations (every milestone)

- The suites stay green in any order and snapshot-restore any state they
  touch; every new guard ships with its violation probe.
- Model IDs and pricing re-verified at each release (they drifted twice in
  eight weeks — 09 § 3); `RATES_VERIFIED` updated or the staleness shows.
- Docs 01–15 are living: a milestone that changes behaviour edits the
  document in the same change-set, IDs never renumbered.
- CHANGELOG entries record *why*, including the bugs found — the project's
  memory has already paid for itself several times.

---

## Risk Watch per Milestone

| Milestone | Risk most likely to bite | Standing answer |
|---|---|---|
| M1 | R-TECH-02 (SSE on shared hosts) | The fallback is a first-class path, not an apology |
| M2 | R-SEC-01/02 under real traffic | Adversarial suite ran first; boundaries, not model virtue |
| M3 | R-BRAND-01 (SEO in a dense cluster) | Job-phrase content strategy; trademark search before M4 |
| M4 | R-MKT-01 (cap wrong), licence stub temptation | Meter is config; stub replacement is ship-blocking |
| M5 | Scope creep into hosted infrastructure | 13 § 5's honest boundary; D8 owns the decision |
