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
| **Streaming (FR-CHAT-02)** — built 2026-08-07: additive `StreamingChatProviderInterface` (Gemini), `CurlSseClient` with proxy constants, Accept-negotiated SSE after every guard, widget token rendering + one whole-message announcement; 22 probes; the SSE-transport failure path exercised live. **Timed incremental delivery verified live 2026-08-08**: `tools/probe-streaming-delivery.php` (the standing measurement — real HTTP, per-chunk timestamps, self-judging verdict) observed 9 deltas at 9 distinct network arrivals over 609 ms, reassembling exactly to the `done` payload, through the full stack. Getting there took eight 429'd attempts across two keys — the free tier's per-model request bucket (limit 20) is far tighter than its retry hint claims (09 § 3), and every refusal re-exercised the failure path: a sentence in `done`, run `failed` with the provider's code | **Open half, narrowed 2026-08-08:** the buffered==streamed *equivalence* is now probed — `tests/browser/sse.spec.mjs` drives the extracted, shipping SSE assembler (`widget-app/src/sse.ts`, transpiled by the project's own TypeScript so the test runs the real code) under buffered / streamed / one-byte-at-a-time / every-split-offset / CRLF delivery, and every pattern reaches the same events and the same `done` payload. What remains is observing it on an *actual* buffering host, which rides the budget-host real-host run (R-TECH-02). 12 § 10 holds and is probed: guards run before the transport is chosen |
| ~~**SKU / exact-identifier tool**~~ | ✅ **Done 2026-08-07.** `product.lookup` resolves exact SKUs (variations included) with live price/stock; unknown, draft, and private SKUs share one indistinguishable miss (no unpublished-catalogue oracle — probed); out-of-stock is named, not hidden; identifier fixtures in the recall harness score 3/3 via the exact path |
| ~~**Failover execution**~~ | ✅ **Done 2026-08-07.** One switch to the configured fallback, continuing from the request state at failure — executed tools never re-run (probed); both attempts on the run record; both-dead fails after one switch; the settings API validates and stores the fallback key it previously stripped |
| ~~**Merchant guardrail overrides**~~ | ✅ **Done 2026-08-07.** Additive-only, composed after every shipped rule behind a subordinating frame; probed against a hostile "ignore the price rule" override; 01's rescope note and 08 § 8 retired |
| ~~**Per-agent model policy**~~ | ✅ **Done 2026-08-07.** Wired: an agent's override resolves ahead of the global policy; a broken override (unknown/unconfigured provider) degrades to the global resolution, not to a failed turn; failover stays task-level; both paths probed |
| **Onboarding flow (FR-ADMIN-02)** — built 2026-08-08: one `/setup` screen carrying all five steps' real controls inline (11 § 3.7); first activation redirects into it, once; step state derived, never stored (`Core\Onboarding`, 05 § `/bootstrap`); source selection is new capability, not new copy — `POST /index/sources`, honoured by the walker *and* the live save hook, purging what falls out of scope; `GET/POST /agents` finally writes the `enabled` column the orchestrator has always read | **Open half of the criterion:** the ≤ 15 min timing itself, measured on a fresh install by **someone who is not us**. Built and probe-tested is not measured — the target is how long a stranger takes to find the next control, and nothing in this repo can observe that. Protocol below; it has not been run |
| ~~**Escalation notification**~~ | ✅ **Done 2026-08-07.** One email per escalation (the transition, not each failed turn — probed), linking into the inspector; the customer's words are never forwarded by mail; recipient filterable, empty disables |
| ~~**Adversarial suite v2 (12 § 10)**~~ | ✅ **Done 2026-08-08.** `tests/schema/verify-adversarial.php`: a named injection corpus (hostile reviews, policy pages, order notes, product descriptions) delivered through the real tool-result channel, run through one set of boundary assertions by two drivers — a compliant scripted model (always CI, proves every attack of all six boundaries dies at a boundary) and the live configured model (`STORECREW_ADVERSARIAL_LIVE=1`, asserts no breach and reports attacks that reached the boundary; a 429 is a safe non-exercise). Live-observed: `gemini-3.6-flash` called `order.lookup` on an unverified conversation as the injection demanded and the identity gate denied it before execution — a boundary firing, not model discretion |
| **Budget-host validation (R-TECH-03)** — instrument built 2026-08-08: `tools/probe-budget-host.php` prints the host capability report (the kill window the host imposes, cron configuration, CLI-vs-web PHP, memory, Woo/HPOS) and then runs a *full index under a forced-tight kill window* against a synthetic catalogue — driving the real `IndexJob` the way Action Scheduler would and killing it after ~one object per batch — asserting the index still completes across ~150 kills with exact accounting (every object indexed once, monotonic cursor, a heartbeat per batch, stalls reaped), and reporting throughput plus a real-catalogue cost estimate. Self-judging, snapshot-restoring, keyless (nothing embeds); it detaches the job handlers and cancels each reschedule so a stray cron tick cannot race it. Two R-TECH-03 robustness fixes it forced are shipped and probed in `verify-jobs`: a `storecrew_index_batch_seconds` filter to clamp the batch budget under a kill window *tighter* than `max_execution_time` reports (php-fpm `request_terminate_timeout`), and a guarantee that the first object of every batch runs so a slow object on a tight host cannot spin a zero-progress reschedule loop. | **Open half — real host only:** a full index of the merchant's real catalogue on a $5/mo host, timed and costed against the estimate; a day's simulated chat sustained there; and the capability report eyeballed against the host's reality. The instrument is what you run there; protocol below. Not yet run |
| **i18n pass** — done 2026-08-08 for the customer-facing and server surfaces: all user-facing PHP strings wrapped in `__()`/`esc_html__()` under the `storecrew` domain (textdomain loaded on `init`); `languages/storecrew.pot` generated (99 strings); the widget's own chrome (aria-labels, rate-limit/closed/too-long messages) delivered translated on the uncached `/chat/boot` response, since the widget bundles no i18n runtime (no `@wordpress/*`, rule 8); widget made RTL-safe (logical CSS + `dir` from `is_rtl()`) with a browser smoke test asserting the layout mirrors. | **Two deliberate boundaries, documented:** the admin SPA stays English (translating it needs a server string catalog the no-`@wordpress/i18n` decision forces — deferred, not blocking beta), and **model-facing** strings (tool descriptions, tool-result messages) stay English by design — the model replies in the *conversation's* language, so forcing the merchant's locale into them would be wrong |
| **.org compliance pass** — done 2026-08-08 (code + text): **Plugin Check clean on the built dist — 0 errors, 0 warnings** (the 53 `ExceptionNotEscaped` findings resolved by escaping the developer messages and, in the transport layer, a justified file-scoped disable where the flagged values are typed exception metadata, not output; `Plugin.php`'s direct-access guard moved into the header window; the migration/uninstall DB sniffs given the PCP codes; readme short description trimmed). `readme.txt` written; `.distignore` added so the dist ships only `src/`, built `assets/`, `languages/`, `readme.txt`, `storecrew.php`, `uninstall.php`, and a `--no-dev` vendor. GPL-2.0-or-later headers confirmed. The no-egress audit is written for review (12 § 9.1): every outbound call enumerated, both merchant-configured provider traffic, nothing else. | **Manual remainder (design, not code):** the WordPress.org marketing assets — `icon-128x128`/`icon-256x256`, `banner-772x250`/`banner-1544x500`, and screenshots — are a design deliverable for the SVN `assets/` dir, not fabricated here; `readme.txt` lists the five screenshot captions they must match |

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

### The budget-host validation, as a protocol

Written down for the same reason as the fifteen-minute one: it is a
*measurement*, and the part no local suite can close needs a repeatable
procedure or it is not a measurement. The instrument
(`tools/probe-budget-host.php`) already exists and is self-judging; the protocol
is what makes its output mean something.

**Host.** A genuine entry-tier shared plan — the $5/mo tier a first-time
merchant actually buys, not a VPS. What is being measured is the interaction
between our resumable-job design and a host that kills PHP without warning,
runs cron unreliably, and may serve a different PHP in web and CLI (R-TECH-03).
Record the plan, the PHP versions (web *and* CLI), and `max_execution_time` /
`memory_limit` / whether WP-Cron is disabled in favour of real cron.

**Fixture.** A realistic catalogue — the larger the better, because the whole
point is behaviour at a size that cannot finish in one request. Record the
object count and the configured embedding model (its rate is what turns the
index into a bill).

**Step 1 — capability report.** Run `wp eval-file .../probe-budget-host.php`
and, separately, `wp cron test`. The pass condition is not a number: it is that
**every line of the report matches what the host actually does.** The CLI PHP it
prints must match the admin Health screen's web PHP or the mismatch is named;
the derived batch budget must be sane for the host's real kill window; a blocked
loopback must show up here rather than as a mysteriously stalled index later.

**Step 2 — the full index, for real.** Trigger a real index of the whole
catalogue (not the probe's synthetic one — the merchant's actual products) and
let Action Scheduler drive it to completion on the host's own cron. **Record
wall-clock to a drained embed queue, the number of resumes (`index_runs`
heartbeat history), the peak memory, and the final embedding spend against the
pre-run estimate.** The pass condition: it **finishes** — no run left `running`
but heartbeat-dead, no object embedded twice, spend within a small margin of the
estimate. A kill mid-run is expected and is the thing being tested; a kill that
*loses* work or *re-bills* is the failure.

**Step 3 — a day's chat.** Sustain a day's worth of simulated conversations
(the strategy's expected volume for a small store) against the live model.
Record p95 turn latency, any 500s, and any turn that exceeded the 45 s
synchronous ceiling (13 § 2). The pass condition: no storefront fatals, and the
buffered-vs-streamed delivery observed to behave as the equivalence probe
predicts — deltas paint on a pass-through host, the same reply arrives whole on
a buffering one, and the widget is none the wiser (R-TECH-02).

**What the run produces** is the row in 13 § 6's Standing Measurements table:
index runtime and cost on a budget host, measured rather than assumed. Until it
is run, that row stays open and this criterion is half-green — the machinery is
proven locally to survive kills, but the *economics and endurance on real budget
hardware* are not yet observed.

## M2 — Private beta

20–30 stores from the Decent Themes base. Exit: two consecutive weeks with
**zero boundary-violation incidents** and **zero storefront fatals** across
the fleet; deflection ≥ 40% (launch target is 55% at 12 months, not at
beta); ten quotable merchants.

**The instrumentation is built** (2026-08-08). All three of 02 § 7's leading
indicators are readable from one install's own tables via
`tools/beta-metrics.php` — read-only, safe on a live store, and nothing is
transmitted. Collecting a fleet means twenty merchants running it and
pasting the output; that is the price of no external analytics
(FR-DIST-11) and it is deliberate.

Only one of the three needed a new instrument:

| Indicator | Where it comes from |
|---|---|
| **Onboarding step drop-off** | **New.** `Core\SetupProgress` emits a `setup_step.<id>` usage event the first time each step is *observed* complete, once per install. `Onboarding` still derives done-ness from the thing itself and remains the only answer to "is this step done" — what is stored is when we first saw it, which never feeds back into the derivation. |
| **Deflection rate** | Already recorded: `conversations.status` / `escalated_at`. The report counts conversations, not turns, and counts never-escalated as deflected — including abandoned ones. That flatters it, and is the definition the target was set against. |
| **Escalation reasons** | Already recorded, but not where it looks: `ChatService` writes a prose summary into a system message for the merchant's inbox, which cannot be aggregated. The report groups `agent_runs.status` + `error_code` across escalated conversations instead — the queryable form, and the reason Gate 3's `error_code` work matters here. |

Two honesty constraints are built into the recorder rather than left to the
reader. An install that finished setup **before** this shipped has step times
nobody recorded, so it is marked `backfilled` and reports no timings at all —
stamping them at first observation would report a five-second onboarding and
drag every fleet average toward a figure no merchant lived through, which is
the fabricated-zero defect wearing a different hat. And elapsed times are
wall-clock between observations, not attention: a subject who leaves the tab
open over lunch shows an enormous step, which is why the fifteen-minute
protocol still keeps a human observer counting hesitations.

That last point is also why this **does not close M1's fifteen-minute row**.
It makes the measurement repeatable and removes the stopwatch — the report
prints both numbers the protocol asks for, total and total-less-provider-
signup — but the criterion is still three strangers on a fresh install, and
what they stall on is the output that matters.

## M3 — WordPress.org launch (free)

Submission, review remediation, launch content (the job-phrase SEO set,
02 § 5.2). Post-launch: the strategy's 90-day tripwire is armed — flat
installs + setup-difficulty reviews moves the hosted-proxy decision up
(D8). Exit for M4 to begin: **500 active installs, ≥ 4.5★** (D9), support
load ≤ the PRD's 0.4 tickets/customer/month equivalent.

### The submission gate, as a repeatable command

`tools/build-dist.sh` assembles the distribution the way it will be
submitted — `.distignore` applied, front end rebuilt from current source, a
`--no-dev` vendor, `composer.lock` gone — into `../storecrew-dist`. It never
touches the working tree's `vendor/`, because a `--no-dev` install there
deletes phpstan and phpcs and breaks `composer check` with no obvious cause.

Everything below is verified against **that directory**, never the working
tree. Status 2026-08-08:

| Gate | How | Result |
|---|---|---|
| Plugin Check | `wp plugin check storecrew-dist --slug=storecrew` | **0 errors, 0 warnings** — and clean again at `--severity=1` with low-severity errors, low-severity warnings and `--include-experimental` all switched on. `--slug` is what makes this possible: without it the folder name fails the text-domain check and reports a defect that will not exist on `.org`. |
| The check itself works | Plant `echo $_GET[...]` and `eval()` in the dist, re-run | 3 errors + 8 warnings raised, gone when removed. A checker that has never been seen to fail is not a checker. |
| The shipped artifact boots | `STORECREW_FREE_DIR=… tests/integration/run.sh` | **37/37**, same as the working tree. The dist has a different autoloader from the one every other suite exercises, and a plugin that passes every check in the repo and fatals on activation from the zip is the classic `.org` launch failure. Runs automatically when a dist is present, skips loudly when not. |
| Contents | `find` in the script's output | 8 entries, 149 files, 1.3 MB. 123 PHP files lint clean; `vendor/` is autoloader + `psr` only. |
| readme.txt | Read against the `.org` header rules | Short description 127 chars (limit 150); 5 tags; `Stable tag: 0.1.0` matches the plugin header; all required sections present. |

**Still blocking submission, and neither is code:**

- **The SVN `assets/` artwork** — `icon-128x128`, `icon-256x256`,
  `banner-772x250`, `banner-1544x500`, and five screenshots matching the
  captions `readme.txt` already commits to. A design deliverable. These live
  in SVN's `assets/` directory, not in the zip, which is why Plugin Check
  passes without them and why their absence is invisible to every gate above.
- **`Contributors: decentthemes`** must be a real WordPress.org username that
  exists before the plugin is submitted, and the account that submits it.
  Nothing local can check this.

One housekeeping note: `../storecrew-dist` sits in the plugins directory
because `wp plugin check` only sees installed plugins. It declares the same
classes as the working copy, so **activating both fatals**. Remove it once
verified; the harness skipping is the reminder.

## M4 — Pro build & launch (Phase 2)

Order chosen so the licence spine lands first and each agent ships with its
tools' approval defaults:

1. **Licence infrastructure** (10 § 8): server + webhook + `LicenceClient`
   replacing the stub (**ship-blocking**), snapshot verification in
   `FeatureGate`, update server. **The metering substrate FR-LIC-02 rests on
   is built and probe-tested at the boundary** (2026-08-08, the milestone's
   first change-set): `METRIC_CONVERSATION` is written on a conversation's
   first agent *answer* (idempotent; failed turns charge nothing),
   `Licensing\Quota` reads `conversations.monthly` (free default 100,
   loosen-only `storecrew_quota` filter, null = unlimited), `/chat/session`
   declines new conversations at cap while resume and in-progress turns are
   never gated, and the Overview shows the count all month (R-MKT-01).
   **The client half of the spine is also built** (2026-08-08, second
   change-set): `Pro\Licensing\Snapshot` + `LicenceClient` replaced the
   stub — Ed25519 envelope verification failing closed, grace to the
   second, site binding, grant-from-entitlements-map, quota loosening, all
   probe-tested against fixture-signed envelopes in the integration
   harness (37 → 73 assertions). **The activation UI is built** (2026-08-08,
   third change-set): Pro's `/licence` screen arrives through the extension
   seam — AdminRoute + contributed REST controller + plain-JS bundle on the
   shell's new DOM-mount screen registry (06 § 2.3) — live-verified through
   the real REST server. What remains is the remote half: the licence
   server implementing 10 § 6.1's contract, the production keypair
   (`PUBLIC_KEY` empty = fail-closed `unconfigured`, still ship-blocking),
   and updates.
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
