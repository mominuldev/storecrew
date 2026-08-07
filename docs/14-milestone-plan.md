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
| ~~**Static-analysis configs**~~ | ✅ **Done 2026-08-07.** `composer check` = phpcs (WPCS tuned to the documented conventions) + phpstan (level 5, WP+Woo stubs, clean) + `check-invariants.php` (noGlobalWpdb with its carve-outs, noProReferenceInFree, parse-safety — each self-testing) + the DB-free harness. phpunit was declared and used by nothing — removed rather than configured |
| ~~**Pro `uninstall.php`**~~ | ✅ **Done 2026-08-07.** Removes exactly Pro's licence options and nothing the free plugin owns; harness scenario probes both directions (options gone, decoys survive) |
| **Streaming (FR-CHAT-02)** — built 2026-08-07: additive `StreamingChatProviderInterface` (Gemini), `CurlSseClient` with proxy constants, Accept-negotiated SSE after every guard, widget token rendering + one whole-message announcement; 22 probes; the SSE-transport failure path exercised live. **Timed incremental delivery verified live 2026-08-08**: `tools/probe-streaming-delivery.php` (the standing measurement — real HTTP, per-chunk timestamps, self-judging verdict) observed 9 deltas at 9 distinct network arrivals over 609 ms, reassembling exactly to the `done` payload, through the full stack. Getting there took eight 429'd attempts across two keys — the free tier's per-model request bucket (limit 20) is far tighter than its retry hint claims (09 § 3), and every refusal re-exercised the failure path: a sentence in `done`, run `failed` with the provider's code | **Open half of the criterion:** only the buffering-host exercise, which rides the budget-host validation row (R-TECH-02). 12 § 10 holds and is probed: guards run before the transport is chosen |
| ~~**SKU / exact-identifier tool**~~ | ✅ **Done 2026-08-07.** `product.lookup` resolves exact SKUs (variations included) with live price/stock; unknown, draft, and private SKUs share one indistinguishable miss (no unpublished-catalogue oracle — probed); out-of-stock is named, not hidden; identifier fixtures in the recall harness score 3/3 via the exact path |
| ~~**Failover execution**~~ | ✅ **Done 2026-08-07.** One switch to the configured fallback, continuing from the request state at failure — executed tools never re-run (probed); both attempts on the run record; both-dead fails after one switch; the settings API validates and stores the fallback key it previously stripped |
| ~~**Merchant guardrail overrides**~~ | ✅ **Done 2026-08-07.** Additive-only, composed after every shipped rule behind a subordinating frame; probed against a hostile "ignore the price rule" override; 01's rescope note and 08 § 8 retired |
| ~~**Per-agent model policy**~~ | ✅ **Done 2026-08-07.** Wired: an agent's override resolves ahead of the global policy; a broken override (unknown/unconfigured provider) degrades to the global resolution, not to a failed turn; failover stays task-level; both paths probed |
| **Onboarding flow (FR-ADMIN-02)** — built 2026-08-08: one `/setup` screen carrying all five steps' real controls inline (11 § 3.7); first activation redirects into it, once; step state derived, never stored (`Core\Onboarding`, 05 § `/bootstrap`); source selection is new capability, not new copy — `POST /index/sources`, honoured by the walker *and* the live save hook, purging what falls out of scope; `GET/POST /agents` finally writes the `enabled` column the orchestrator has always read | **Open half of the criterion:** the ≤ 15 min timing itself, measured on a fresh install by **someone who is not us**. Built and probe-tested is not measured — the target is how long a stranger takes to find the next control, and nothing in this repo can observe that. Protocol below; it has not been run |
| ~~**Escalation notification**~~ | ✅ **Done 2026-08-07.** One email per escalation (the transition, not each failed turn — probed), linking into the inspector; the customer's words are never forwarded by mail; recipient filterable, empty disables |
| ~~**Adversarial suite v2 (12 § 10)**~~ | ✅ **Done 2026-08-08.** `tests/schema/verify-adversarial.php`: a named injection corpus (hostile reviews, policy pages, order notes, product descriptions) delivered through the real tool-result channel, run through one set of boundary assertions by two drivers — a compliant scripted model (always CI, proves every attack of all six boundaries dies at a boundary) and the live configured model (`STORECREW_ADVERSARIAL_LIVE=1`, asserts no breach and reports attacks that reached the boundary; a 429 is a safe non-exercise). Live-observed: `gemini-3.6-flash` called `order.lookup` on an unverified conversation as the injection demanded and the identity gate denied it before execution — a boundary firing, not model discretion |
| **Budget-host validation (R-TECH-03)** | Full index + a day's simulated chat on a $5/mo shared host; capability report matches reality |
| **i18n pass** | All strings translatable; `languages/` builds; RTL smoke test on the widget |
| **.org compliance pass** | Plugin-check clean; readme.txt; assets; GPL headers; the no-egress audit (12 § 9) documented for review |

Exit: all above green **and** the full suite + integration harness + both
browser verifications pass on WP current and current-1, Woo current, PHP 8.3.

### The fifteen-minute measurement, as a protocol

Written down because the criterion is the one M1 row no test can close, and an
unrepeatable measurement is not a measurement. Runs are only comparable if
these are held fixed.

**Subject.** Someone who has not seen this product and is not on the team — a
WooCommerce merchant or an agency contact. **Three subjects minimum.** One
person is an anecdote, and the failure mode being measured (where does a
stranger stall?) is exactly the one that varies between people.

**Fixture.** Clean WordPress + WooCommerce with a realistic catalogue, and the
plugin **never previously activated** on that site — the first-activation
redirect is part of what is being measured and only fires once
(`storecrew_setup_redirect`). Record the catalogue size with the result.

**The clock starts** when the subject clicks Activate on the plugins screen.
**It stops** when the setup flow reads 5 of 5. Note that step 3 completes when
the crew can answer from *something*, not when the queue drains — embedding
scales with the catalogue and is not the subject's time to spend.

**Two numbers, both recorded.** Total wall-clock, and wall-clock minus the
detour into the provider's own site to make an account and generate a key. The
second is what this row's ≤ 15 min applies to; the first is what the merchant
actually lives through. If the gap is large the finding belongs to the BYO-key
cliff (02 § 5.3, D8's hosted-proxy tripwire), not to this UI, and confusing the
two would send the next sprint to the wrong place.

**The observer records** entry and exit time per step, every question asked
aloud, every hesitation over ~15 seconds, and every click that was not the next
step. Those are the result; the elapsed time is only the headline.

**Pass** is ≤ 15 min on the second number with **no observer intervention**.
Any intervention fails the run, and what the subject was stuck on is the actual
output — a passing time bought by a hint measures the hint.

Known drags to watch for, so they are not rediscovered as surprises: provider
signup dominates the total and is outside our control; a store with no
published policy pages gives step 3 little to read and makes the first answers
disappointing; and the widget step is invisible until the subject opens the
storefront, which they will not do unless told.

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
