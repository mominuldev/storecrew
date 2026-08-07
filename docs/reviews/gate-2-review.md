# Gate 2 Review — Findings

**Date:** 2026-08-07
**Scope:** 03 Technical Architecture, 04 Database Schema, 05 REST API
Specification, 07 Folder Structure.
**Method:** four independent verification passes (one per document), each
checking every falsifiable claim against `src/`, the live database
(`SHOW CREATE TABLE` on all eleven tables), and the live REST route table;
plus a full run of all nine schema suites (**566 assertions, 0 failures**)
and the DB-free integration harness (all probes passed).

> **Outcomes (2026-08-07, same day):** all four blockers below are **fixed and
> probe-tested** — G2-C1 via the `storecrew_retrieval_performed` action and a
> run-scoped listener in `AgentRunner` (accumulating, best-score-wins,
> ids-only); G2-C2 via argument redaction in `ToolExecutor` (key-based plus a
> pattern pass; the tool still receives raw values; the
> `storecrew_redacted_argument_keys` filter may only add); G2-C3 via a
> `rest_post_dispatch` filter marking every `/chat/*` response `no-store`,
> errors included; G2-C4 via a `SpendGuard` check ahead of the routing
> classifier. The suites grew from 566 to **583 assertions**, all green.
> The § 4 doc-stale edits are applied to 03/04/05/07. Of the § 5 decisions:
> G2-D1 is dispositioned by documenting the carve-outs, G2-D2 by re-tensing
> retention/GDPR to *planned* (building them remains open), G2-D3 by
> rewording to convention-checked (writing the configs remains open). The
> minor code issues under § 1 remain open as tickets.
>
> One additional defect surfaced during remediation, unrelated to the four:
> `verify-repositories` was poisoning its own future runs — InnoDB keeps
> deleted rows in the FULLTEXT index and its term statistics until an
> OPTIMIZE, so each run's three deleted probe chunks decayed the IDF of the
> probe's own search terms until the lexical-search assertion began failing,
> dozens of runs after the cause. The suite's cleanup now OPTIMIZEs the
> chunks table, restoring the "green in any run order" property (and adding
> the lesson to CLAUDE.md's bug table).

**Verdict: not approvable as written.** The documents are structurally
excellent — the schema matches the migration and live DB byte-for-byte, all
21 REST routes verify field-exact, the registration window, security
boundary, FR-KB-08, and folder trees all check out — but the review found
**four code defects behind documented guarantees**, plus doc sections that
describe superseded designs or unbuilt features in the present tense. Gate 2
approval would bless those claims, so the gate stays open until each finding
is dispositioned.

---

## 1. Code defects (blockers — the doc is right, the code is not)

| ID | Finding | Evidence |
|---|---|---|
| **G2-C1** | **Retrieval provenance is never recorded.** 03 § 11 promises every agent run records retrieved chunk ids/scores; `SharedContext::set_retrieved()` has no production caller (only `verify-agents.php` calls it). Retrieval tools receive a `ToolContext` with no path back to `SharedContext`, so every production run stores `retrieved = []`. Schema, repository method, and the ids-only projection all exist — only the wiring is missing. The conversation inspector's provenance feature does not work. | `src/Agent/SharedContext.php`, `src/Agent/AgentRunner.php` (finish), `src/Agent/Tools/ProductSearchTool.php` |
| **G2-C2** | **Raw customer email persisted.** 04 § 11 states "no raw email addresses … are stored in any table"; `ToolExecutor` persists tool arguments verbatim to `wp_scr_tool_calls.arguments`, and `identity.verify` takes the customer's billing email as an argument — so every verification attempt (including failed ones) writes a raw email. No redaction exists anywhere in the plugin. Fix is code (argument redaction for identity-bearing tools), not a doc edit. | `src/Agent/Tool/ToolExecutor.php`, `src/Agent/Tools/IdentityVerifyTool.php` |
| **G2-C3** | **`/chat/boot` "never cached" is unenforced.** Nothing sets `Cache-Control: no-store`; WP core's nocache headers only apply to logged-in users, and `/chat/boot` exists for anonymous visitors. On a host whose CDN caches REST GETs, one visitor's boot payload — a fresh nonce and, for a resuming visitor, their transcript — can be served to another. One header line plus a probe fixes it. | `src/Api/Rest/Controllers/ChatController.php` |
| **G2-C4** | **The routing classifier call bypasses `SpendGuard`.** 03 § 5 claims the guard runs "before any call is made"; `allows_call()` is checked in `AgentRunner` and `Indexer` only. `Orchestrator::route()` makes its provider call before `AgentRunner::run()` is reached, unguarded — a store past its cap still pays for routing on every turn. | `src/Agent/Orchestrator.php`, `src/Ai/SpendGuard.php` |

Minor code issues found alongside (not blockers, worth tickets):

- `storecrew_needs_upgrade` is write-only — written by `Activator`, deleted
  by `Migrator`, never read as a condition (03/04 both cite it as the
  trigger; the real gate is a version comparison).
- `dense_full` hard-codes `truncated: false` as a literal — currently
  correct, silently wrong if `DENSE_SCAN_THRESHOLD` ever exceeds
  `MAX_DENSE_SCAN`.
- `storecrew_retrieval_truncated` and `storecrew_registry_rejected` have no
  listener and no probe test — by 03's own "a rule that has never fired is
  treated as not existing" criterion, neither guard exists yet.
- Pro has no `uninstall.php`, but the free plugin's `uninstall.php` states
  premium removes its own options there (FR-DIST-06). Pro's licence options
  are never cleaned up.
- `composer.json` scripts (`lint`, `analyse`, `test`) reference phpcs /
  phpstan / phpunit configs that do not exist in either plugin; the
  `storecrew.noGlobalWpdb` rule cited as "enforced by static analysis" is
  not implemented anywhere.
- `verify-admin.php` fatals (uncaught `TypeError`) when run without
  `--user=1`, and silently requires `storecrew-pro` active (it asserts a
  Pro route). Neither precondition is recorded in 03 § 12's harness table.

---

## 2. Doc-ahead-of-code in the present tense (decide: build or re-tense)

| ID | Finding |
|---|---|
| **G2-P1** | 04 § 11 retention table: four of five rows are unimplemented. Only audit-log pruning exists (and matches the doc exactly, including the 6-month floor). Conversations/messages, agent_runs/tool_calls, and usage_events pruning do not exist; `AuditLogRepository::prune()` is the only `prune()` in any repository. |
| **G2-P2** | 04 § 11: "GDPR erasure is wired to the WordPress personal-data exporter and eraser" — nothing is wired; zero privacy-filter registrations in the tree. The doc describes an unbuilt feature in the indicative mood. |
| **G2-P3** | 04 § 5.1: the `source_type` enum lists nine values; two (`product`, `post`, with `page` through the same extractor) are implemented. Live data confirms only those. |

---

## 3. Superseded design — retrieval (§ 6 of 03 and 04)

The single largest documentation gap: **04 § 6 documents the retrieval
design that preceded measurement.** As built, `search()` has five outcomes
(`dense_full` / `hybrid` / `dense_fallback` / `lexical_only` / `empty`);
below `DENSE_SCAN_THRESHOLD = 2000` embedded chunks the prefilter is skipped
entirely and every vector is scanned (the accurate path — and the default
for any realistic small store); the FULLTEXT queries run in natural-language
mode, not the documented boolean mode; `DEFAULT_DENSE_WEIGHT = 1.0` means
the lexical arm contributes zero to ranking by default; and the doc's first
fallback ("widen the prefilter") has already been ruled out in code with a
worked example. The recall measurement 04 § 12 presents as pending has been
taken: lexical-prefilter recall@3 = 0.80 — **below the doc's own 0.88
bar** — vs 1.00 full-dense on the 10-question set, 0.96 at weight 1.0 on the
current 23-fixture set. 03 § 6 carries the stale 0.80-vs-1.00 pair without
saying the corpora differ or that the current figure is 0.96.

Also: the recall fixtures exist only as docblock prose; the fixture set
behind the two most consequential retrieval constants should be committed
(`tools/measure-recall.php` covers 23 fixtures but requires a live embedding
provider to re-run).

---

## 4. Doc-stale edits (absorb into the documents)

**03 Technical Architecture** — registry rejection in production fires
`storecrew_registry_rejected`, it does not log (and nothing listens);
priority-5 row omits extractors (one of the seven frozen registries);
"user-role content" should read "tool/user-role content, never system"
(OpenAI-compatible providers use `role: 'tool'`); "below 2,000 chunks" is
`<=` at the boundary and conditional on a query vector existing;
`SpendGuard` wording must reflect `BEHAVIOUR_WARN` and the uncapped default.

**04 Database Schema** — § 6 rewrite per section 3 above; migration trigger
is an unconditional `admin_init` hook gated by version comparison (plus an
undocumented `init`-under-WP-CLI trigger, which is correct and worth
documenting); JSON cap is 65,535 bytes, applies to every JSON column via the
`Repository` base, and *replaces* oversized payloads with
`{"_truncated":true,"_bytes":N}` rather than clipping; "auto-increment ids
never exposed" should be scoped to the public/widget surface (admin routes
legitimately emit run/call ids).

**05 REST API Specification** — `/knowledge/search` strategy values are
`hybrid | lexical_only | dense_fallback | empty` (doc says "dense /
prefilter"); `/knowledge/sources` returns `{statusCounts, needingIndex}`,
not a per-source list with chunk counts; `/index/start` returns an
undocumented 503 (`storecrew_queue_unavailable`) *before* the 409;
`/index/embed` returns 202; four documented 400s carry WP-core codes
(`rest_invalid_param` etc.), not `storecrew_*` — clients told to "branch on
codes only" need the exception stated; the WP-generated namespace index
(`/storecrew/v1`, anonymous, enumerates all routes) falsifies "no route
without a permission callback" as an absolute; the § 3 lede overstates the
guard — only `session()` and `send()` call `guard()`; `history()` and
`close()` require ownership only (defensible, should be stated);
`/health` has no `stranded` field (`pending` + `mismatched`);
`storecrew_view_analytics` and `storecrew_converse` gate no REST route
(converse is used at the tool boundary).

**07 Folder Structure** — `languages/` is documented (and referenced by
`load_plugin_textdomain` and the `Domain Path` header) but does not exist;
"vendor: Composer autoloader only, no runtime deps" is falsified by
`psr/container` (interfaces-only — reword, don't remove); provider list
omits `OpenAiCompatibleProvider` (6 files, not 5) and `ProviderInterface.php`
next to the documented "deliberately separate" chat/embedding interfaces;
`src/Database/` omits `MigrationInterface.php` and `Repository.php` (the
latter is load-bearing — it is where `$wpdb` actually lives); root-file
enumeration omits `.gitignore` and `package-lock.json` (release-relevant:
the .org-zip exclusion list doesn't mention it).

---

## 5. Decisions for the product owner

| ID | Decision |
|---|---|
| **G2-D1** | The "only repositories touch `$wpdb`" absolute vs `PagesPostTypeIds.php`, which uses `$wpdb->prepare()` inside a `posts_where` filter (keyset pagination). Defensible exception — but either the docs record the carve-out or the extractor is refactored. The cited-but-unimplemented `storecrew.noGlobalWpdb` rule would trip it. |
| **G2-D2** | Retention + GDPR (G2-P1/P2): implement now, or re-tense the docs to "planned" and schedule. Shipping to .org with a privacy section that overpromises is the worst of both. |
| **G2-D3** | Static analysis: write the phpcs/phpstan configs the composer scripts and docs reference, or strike "enforced by static analysis" and say "by convention, spot-checked at review". |

---

## What verified cleanly (the short list)

All eleven tables byte-for-byte (migration ↔ live DB ↔ doc); the vector
format confined to one class (67/67 live rows at exactly 6,144 bytes); the
metering upsert verbatim; `identity_verified` reset on customer change;
salted IP hashing with no raw-IP column; all 21 routes with methods,
permissions, and response shapes field-exact; the session-token model
(issue/digest/cookie-wins/rebind/uniform-404) exact; deny-by-default with
exactly five `public_access()` endpoints; the registration window and freeze
(probe-tested); lazy controllers/tools/job-handlers; the ToolExecutor
authorisation order and deny-only filter; guardrails-after-persona;
FR-KB-08 at the extractor; `LEXICAL_FLOOR` / `DENSE_SCAN_THRESHOLD` /
dense-weight constants as documented; the Scheduler cancel-without-group
constraint; the append-only audit log (no update method exists); both
bundle-size budgets (94 KB admin gz, 5.3 KB widget gz); no `@wordpress/*`
anywhere; PHP 5.6 parse-safety of all four guard files (manual token scan);
zero `StoreCrew\Pro` references in free `src/`; 566/566 assertions and the
full integration harness green.
