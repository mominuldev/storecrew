# Changelog

All notable changes to StoreCrew AI are recorded here.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this
project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The plugin is **pre-release**. Everything below is under `[Unreleased]` until
0.1.0 ships; the dated sections are development milestones, not releases.

---

## [Unreleased]

### Added

**Revenue attribution: the link that never existed** — 2026-08-08

- FR-ANALYTICS-03 asked for revenue influenced by StoreCrew conversations
  "with methodology stated", and there was nothing to state a methodology
  *about*: no record joined a conversation to an order.
  `conversations.verified_order_id` looks like one and is not — it records
  that a customer proved who they were against an order they already had,
  which is identity verification pointing backwards. `scr_attributions`
  (Migration005) points forwards.
- **Recorded at checkout, because that is the only moment it is visible.**
  `Chat\OrderAttribution` listens on both the classic and Blocks checkout
  hooks, where the shopper's browser is still presenting the chat session
  cookie. An hour later nothing can tell which conversation belonged to the
  person who bought, which is why this is recorded rather than derived.
  Deliberately not `woocommerce_new_order`: that fires for orders an
  administrator creates in wp-admin, where the cookie belongs to whoever is
  staffing the shop.
- **The table holds no money.** No revenue column, no currency, no captured
  total — the row is a link, and the amount is read live from the order when
  a report asks. A refunded order therefore stops counting on its own. This
  is FR-KB-08's rule (volatile values are never indexed) pointed at revenue,
  and it is why a merchant can never read a figure WooCommerce no longer
  agrees with.
- **`order_id` is unique**, so the model is last-touch by construction
  rather than by convention — and the doubled checkout hook, which fires
  twice on a store running both checkouts, is idempotent for free rather
  than by luck.
- A link needs a **storefront** conversation (a merchant console thread is
  never a customer's shopping conversation), at least one answer from the
  crew in it, and a last activity inside the window —
  `storecrew_attribution_window_days`, 7 days, clamped to 1–90 because a
  window measured in years does not measure attribution, it measures having
  ever had a conversation.
- **`Api\Attribution` is the fourth widened API surface**, and it publishes
  `methodology()` alongside the reads. The description of what the links
  mean is written by the code that records them; kept beside the reader
  instead, it drifts, and a merchant told something false about a number
  that is otherwise correct is worse off than one told nothing. The
  methodology states what it **cannot** see as well as what it can — the
  figure is a floor, because a shopper who chats on a phone and buys on a
  laptop is invisible to it.
- Retention and GDPR erasure both sever the links. A link to a conversation
  nobody can open is a revenue figure nobody can check; attribution history
  is bounded by conversation retention, and the report says so.
- New `tests/schema/verify-attribution.php`, 29 probes against real MySQL
  and real WooCommerce orders it creates and destroys itself.

### Fixed

**A test suite could delete the site's administrator** — 2026-08-08

- `verify-repositories` cast `wp_insert_user()`'s return with `(int)` and no
  `is_wp_error()` check. **`(int)` on an object is `1` in PHP 8**, with a
  warning nobody reads, and 1 is the administrator. A crashed earlier run
  left a probe user holding the fixture email; the next run's insert failed
  as a duplicate, and the suite then ran its erasure probes against user 1
  and finished by deleting it. **This happened, on this repository's own dev
  site.**
- Now guarded four ways: `is_wp_error()`, a refusal to run against any id
  ≤ 1, a guard at the `wp_delete_user()` call itself, and a sweep for a
  leftover probe user on entry — because restoring state on the way out is
  no use if the suite cannot start again.
- Same defect family as the `null === ( $x['k'] ?? 'fallback' )` probe bug
  already on record: an unchecked conversion producing a *plausible* value
  instead of failing loudly.

### Added

**Agents declare who they answer, and merchants get a console to talk to
them in** — 2026-08-08

- `Agent::$audience` — `storefront` (the default) or `admin`. Routing, the
  classifier's catalogue, and `agent.handoff`'s target list all read
  `Orchestrator::available_agents()`, which defaults to the storefront set,
  so a merchant-facing agent cannot be routed to from the widget, cannot be
  handed a conversation, and is never described to the classifier. It is
  reached by name through the new `Orchestrator::converse()` and by nothing
  else.
- The reason is concrete rather than architectural. Premium's first agent
  reads customer purchase history and creates coupons; registering it into
  the shared registry without this would have made it answerable to anyone
  who opened the chat widget. An unrecognised audience **throws** at
  construction rather than defaulting, because a misspelling falling back to
  `storefront` fails in the one direction that costs the merchant.
- `handoff()` now resolves its target from the storefront availability set
  rather than the raw registry. The tool already validated against that
  list, but a handoff must not be the single path where entitlement, the
  merchant's enable switch, and the audience boundary do not apply.
- `ToolContext::is_storefront` — declared since the executor was written and
  read by nothing — is now derived from the agent's audience and is real
  substrate. It was computed as `! is_admin()`, and both surfaces arrive
  over REST where that is false either way, so every merchant turn would
  have described itself as a storefront turn.
- `Chat\ConsoleService`: one merchant turn, end to end, on the new `console`
  conversation channel. No routing (the merchant chose a screen), no
  identity verification (they are an authenticated user), no escalation (the
  human is typing), and **no conversation quota** — the free-tier unit is a
  *customer* conversation, and charging a merchant for asking their own
  agent a question is the fabricated-figure rule pointed at the merchant.
  Tokens and spend are still metered, because those are real.
- The two channels are kept apart in the *lookups*, not just the labels:
  `find_open_for_session()`, `find_open_for_customer()`, and `recent()` are
  channel-scoped, and `ChatService::authorise()` refuses any non-widget
  conversation outright. A shop manager holds one WordPress user id on both
  surfaces, so an unscoped lookup — or the FR-CHAT-05 cross-device path,
  which is reached by uuid and keyed on that id — would have handed the
  storefront widget the merchant's own console thread, and then answered it
  with a storefront agent.
- The agent roster (`GET /agents`) now reports `audience`. The console lists
  every agent side by side, and an on/off switch over an agent whose reach
  you cannot see is a switch you cannot reason about.
- Two suites were counting the *global* registry rather than what the free
  plugin owns, so an add-on contributing an agent or a tool failed them —
  reporting a healthy platform as broken on exactly the installation the
  extension API exists to serve. Both now count by owner.

**Approving a write now carries it out** — 2026-08-08

- `ToolExecutor::execute_approved()`, and `POST /approvals/{id}` calls it.
  Until now approval stamped the row and stopped: the model was told the
  write was queued, the merchant approved it, the card left the queue, and
  **nothing happened**. It hid because the only write tool that shipped was
  `order.note`, and a missing note reads as the agent choosing not to leave
  one. Found while building the Marketing agent, whose whole point is a
  coupon that exists afterwards.
- **Approval is the claim.** `approve()`'s `required → approved` transition
  carries the pending state in its WHERE, so it is the mutex: a double-click,
  a double-submit, and two administrators deciding at once all lose the race
  quietly. Nothing executes twice.
- **Authorisation is re-derived, never replayed.** The tool, the agent's
  allow-list, its audience, the per-tool mode, the conversation's identity
  state, and the capability check are read at approval time. Verification
  revoked between queueing and approval — a different customer signing in on
  a shared device — revokes the write with it. The capability checked is the
  approver's, because they are the one taking responsibility.
- A failure between the claim and the result leaves the row `approved` +
  `pending`, which `approve()` never matches again: a stuck row a merchant
  can re-ask for, rather than a coupon issued twice.
- **A write whose arguments redaction altered can no longer be queued at
  all.** Arguments are stored redacted (04 § 11), so replaying them would
  carry out something different from what was approved — an order note with
  the customer's email replaced by `[redacted]`. `execute()` compares the two
  forms, refuses to queue when they differ, and tells the model to re-ask
  without the personal detail. The design rule that falls out: an
  approval-gated tool should take an id, not an email.
- The route reports what actually happened. A refusal or failure comes back
  as an error, which the Inbox already renders on the card the merchant
  clicked — a write that quietly did not happen is precisely what this
  stopped being possible.
- Verified live end to end: the marketing agent was asked for a coupon, the
  write queued, `POST /approvals/{id}` returned `executed: true`, and
  WooCommerce had the coupon with the right code, discount, expiry, and
  per-customer limit. Re-approving returned 409 and created no second coupon.

**Still open:** `tool_modes` is stored with no route and no UI, so a merchant
cannot set a tool to `auto` and skip the queue. With approval executing, that
is a convenience rather than a blocker.

**Settings grows a tab seam, and the licence pane moves into it** — 2026-08-08

- `window.storecrew.registerSettingsTab(id, { label, mount })` is the
  client-side registry's second surface: an add-on contributes a pane to
  the Settings screen's tab bar instead of a whole sidebar route. Same
  DOM-mount contract as `registerScreen`; the label arrives already
  translated because the shell has no catalog for words it has never heard
  of. A contributed tab joins the `?tab=<id>` URL contract, so a deep link
  pasted into a support thread lands on the pane, and the Settings screen
  subscribes to the registry — a tab registered after first render appears
  without a refresh.
- The reasoning is placement, not plumbing: a sidebar entry is for a place
  the merchant works, and a form they visit twice a year belongs with the
  rest of the configuration. First consumer: Pro's licence pane, which
  dropped its `/licence` AdminRoute the same day it gained one (see the
  Pro changelog for the redesign).
- The harness now asserts the seam from both ends, the same shape as the
  Update URI/filter-name probe: the built shell bundle exposes
  `registerSettingsTab`, the licence bundle calls it and no longer calls
  `registerScreen`, and `/licence` is *not* a declared route. Boot suite:
  98 → 99 assertions.

**The harness probes the updater's whole failure surface** — 2026-08-08

- Pro's licence-gated updater (see the Pro changelog) is probed here through
  fixture transports: header-hostname/filter-name agreement (the pair that
  fails silently if it drifts), no-key silence with the server never called,
  the withheld-package lapsed path with its renewal sentence, dead-server
  and malformed-answer silence, and a non-https package poisoning the whole
  answer. Boot suite: 84 → 98 assertions.

**Add-on screens are a DOM mount, and the shell says when to arrive** — 2026-08-08

- The admin shell's client-side screen registry
  (`window.storecrew.registerScreen`) now takes `{ mount(el) → cleanup? }`
  instead of a React component. The old contract was unimplementable from
  outside: this app bundles its own React, so an add-on had no instance to
  build elements with, and honouring the component signature would have
  forced every add-on to ship a second React — the exact thing FR-DIST-12
  forbids. The registry is also reactive now, because add-on bundles load
  after the shell's first render and a screen registered late must appear
  without a refresh.
- `AdminPage` fires `storecrew_admin_assets` (with the shell's script
  handle) after enqueueing the application, so add-ons enqueue their screen
  bundles at exactly the right moment and depend on the handle — the
  guarantee that `registerScreen` exists before their code runs. First
  consumer: Pro's licence screen.
- `verify-rest` counted controllers in total and failed the moment Pro
  contributed its first one — an add-on doing exactly what the registry is
  for read as a defect in the free plugin. It now counts free-owned
  controllers.

**The integration harness now probes the licence spine** — 2026-08-08

- Pro's `Snapshot`/`LicenceClient` replaced the licence stub (see the Pro
  changelog for the substance); the boot suite here grew from 37 to 73
  assertions, driving the real verification code with envelopes signed by
  an Ed25519 keypair minted per run. The shim gained `home_url`,
  `WP_Error`/`is_wp_error`, `wp_json_encode`, and an observable
  single-event cron surface so scheduling is assertable.
- Docs 10 and 14 updated in the same change-set: § 4 records the
  sign-the-bytes envelope decision, § 6.1 fixes the server contract the
  built client now dictates, § 8's client row flips to built.

**The conversation meter, its quota reader, and the free-tier cap
(FR-LIC-02, 10 § 5 — M4.1's substrate)** — 2026-08-08

- `METRIC_CONVERSATION` is finally written, three gates after it was declared:
  `ChatService::send()` records it when a conversation first receives an agent
  *answer*. Not on open (widget boots stay free of side effects), not per
  message, and not on a failed or refused turn — quota is spent only when the
  customer got something, because the alternative is the fabricated-figure
  defect billed to the customer. `record_conversation()` is a NOT EXISTS
  insert against the event log, so the second answered turn is a no-op rather
  than a double charge.
- `Licensing\Quota` reads `conversations.monthly` (free tier: 100, D1) and
  nothing else — `sites` waits for a consumer, because inventing the key now
  is the built-but-unconsumed defect with a smaller font. The
  `storecrew_quota` filter is **loosen-only** (null = unlimited; below-free
  values clamp back up), the same one-direction contract as
  `storecrew_feature_enabled`: a lapsed licence degrades *to* free, never
  below it. An unknown quota key is unlimited, loudly under `WP_DEBUG` —
  the opposite default from `FeatureGate`, since a typo that silently capped
  a storefront would be fabricated protection pointed at the customer.
- The cap is enforced at exactly one point: `POST /chat/session`, opening a
  **new** conversation (503 `at_capacity`; the widget renders a
  boot-delivered string). Resume is checked before the cap and the message
  POST is never gated, so a conversation in progress always finishes — the
  cap declines new work, it never abandons a customer mid-question. The
  Overview shows used/limit all month via `/health` (R-MKT-01: the count is
  visible long before the cliff).
- **Bug avoided by probing on a configured store:** the suite itself would
  have been the first casualty of the cap — a store genuinely at capacity
  fails every "a session opens" probe, which is the cap working and the
  suite lying. `verify-chat` now pins quota to unlimited for its run, drops
  the pin only inside the cap section (where a quantity-100 probe event
  vaults the counter past any real limit), and its cleanup was taught that
  conversation-meter events carry no provider tag — without the extra
  delete-by-conversation-id sweep, every suite run would have inflated the
  merchant's real monthly count by its own probe conversations.

**The WordPress.org submission gate, as a command** — 2026-08-08

- `tools/build-dist.sh` assembles the distribution exactly as it will be
  submitted: `.distignore` applied, the front end rebuilt from current source, a
  `--no-dev` vendor, `composer.lock` removed after installing from it. The
  assembly previously existed only as prose in `.distignore`'s header, and a
  verification you cannot repeat is one you cannot trust the second time. It
  builds into a sibling directory and never runs `--no-dev` against the working
  tree's `vendor/`, which would delete phpstan and phpcs and break
  `composer check` with no obvious cause.
- **Plugin Check on the actual dist: 0 errors, 0 warnings** — and still clean
  with `--severity=1`, low-severity errors, low-severity warnings and
  `--include-experimental` all enabled. `--slug=storecrew` is required: without
  it the build directory's name fails the text-domain check and reports a defect
  that will not exist on `.org`.
- **The checker was probe-tested rather than trusted.** Planting
  `echo $_GET[...]` and `eval()` into the dist raised 3 errors and 8 warnings,
  which vanished when removed. "No errors found" from a tool that is silently
  not reading your files looks identical to success.
- **The shipped artifact is now booted, not just linted.** `test-boot.php` takes
  `STORECREW_FREE_DIR`, so the integration harness can load a built dist instead
  of the working tree — 37/37, same as the repo. The dist's `--no-dev`
  autoloader is a different artifact from the one every other suite exercises,
  and a plugin that passes every check in the repo then fatals on activation
  from the zip is the classic `.org` launch failure. `run.sh` runs this pass
  automatically when a dist is present and skips loudly when not.
- Dist contents recorded: 8 entries, 149 files, 1.3 MB; 123 PHP files lint
  clean; `vendor/` reduced to the autoloader and `psr/container`. readme.txt
  re-read against the `.org` header rules — 127-char short description, 5 tags,
  `Stable tag` matching the plugin header.

### Notes

- Two things still block submission and neither is code: the SVN `assets/`
  artwork (icons, banners, and the five screenshots `readme.txt` already commits
  to — they live in SVN, not the zip, which is why every gate above passes
  without them), and `Contributors: decentthemes` needing to be a real
  WordPress.org account before submission.

**Private-beta instrumentation — the three leading indicators** — 2026-08-08

- `tools/beta-metrics.php` prints all three of 02 § 7's leading indicators from
  one install's own tables: onboarding step drop-off with elapsed times,
  deflection rate, and escalation reasons. Read-only, safe on a live store, and
  nothing is transmitted — FR-DIST-11 gates telemetry and there is none. A fleet
  is collected by asking twenty merchants to run it and paste the output, which
  is the cost of having no external analytics and is the deliberate trade.
- Only one indicator needed a new instrument. **Onboarding step events** did not
  exist: `Core\SetupProgress` now emits a `setup_step.<id>` usage event the first
  time each step is *observed* complete, once per install. Knowing 40% never
  finish is a number; knowing they all stop at the provider key is a decision.
- `Onboarding` is untouched and still derives completion from the thing itself.
  What is stored is *when we first saw* a step done, which never feeds back into
  the derivation — a stored "step 3 done" flag is precisely what that class
  exists to make impossible.
- **An install that finished setup before this shipped is marked `backfilled`
  and reports no timings.** Stamping its steps at first observation would claim
  a five-second onboarding and drag every fleet average toward a figure nobody
  lived through — the fabricated-zero defect wearing a different hat. Unknown
  reads as unknown, and the install is excluded from the sample.
- Deflection and escalation reasons needed no instrument, but escalation reasons
  were not where they looked: `ChatService` writes a prose summary into a system
  message for the merchant's inbox, which cannot be aggregated. The report groups
  `agent_runs.status` + `error_code` across escalated conversations instead — the
  queryable form, and where Gate 3's `error_code` work pays off. Against this
  store's real traffic: 28 conversations, 6 escalated, 78.6% deflection, every
  escalation a provider 429 or 400.
- This does **not** close M1's fifteen-minute row. It removes the stopwatch and
  makes the run repeatable — the report prints both numbers the protocol asks
  for, total and total-less-provider-signup — but the criterion is still three
  strangers on a fresh install, and elapsed time between observations is not
  attention.

### Fixed

- **`register_shutdown_function` does not survive a fatal under `wp eval-file`.**
  The suites' snapshot-restore safety net was believed to hold through a mid-suite
  fatal; measured, it does not — WordPress registers its own fatal handler first
  and ends the request there. It *does* run on the `exit(1)` a failing suite
  takes, and plain PHP runs it in both cases, which is where the belief came
  from. `verify-rest` and `verify-schema` now call their restores explicitly as
  well as registering them, and assert the cleanup. `verify-knowledge` still
  relies on the shutdown alone — recorded in CLAUDE.md as open.
- `verify-admin` dispatches `/bootstrap` and so began writing real step events
  stamped *now*, days after the merchant actually completed those steps, plus a
  ledger that would stop the install ever being recognised as one whose times
  are unknown. Caught by checking the table after a full suite run rather than by
  a failing assertion. Both suites that dispatch `/bootstrap` now snapshot the
  ledger and delete their own event rows by id, so a merchant's own onboarding
  rows survive.

**WordPress.org compliance pass** — 2026-08-08

- **Plugin Check is clean on the built dist: 0 errors, 0 warnings.** The bulk
  (53 `WordPress.Security.EscapeOutput.ExceptionNotEscaped` errors) was resolved
  by wrapping the developer-facing exception messages in `esc_html()`; the two
  HTTP-transport files (`HttpClient`, `CurlSseClient`), where every throw carries
  typed provider metadata (status code, retryability) that cannot be escaped
  without breaking its type, carry a justified file-scoped `phpcs:disable` for
  that one sniff. `Plugin.php`'s direct-access guard moved into the file header
  where the check looks for it; the migration and uninstall DB queries got the
  Plugin-Check sniff codes added to their existing justified ignores.
- `readme.txt` in WordPress.org format (header, description, FAQ, screenshots,
  changelog), distinct from `CHANGELOG.md`.
- `.distignore` so the distributed zip ships only `src/`, the built `assets/`,
  `languages/`, `readme.txt`, `storecrew.php`, `uninstall.php`, `composer.json`,
  and a `--no-dev` `vendor/` (autoloader + `psr/container`) — not the tests,
  tools, docs, app sources, or static-analysis config.
- **No-egress audit** written for review (docs/12 § 9.1): every outbound call in
  the shipped code enumerated (two sites, both the merchant's configured
  provider), with confirmation there is no telemetry, no web fonts, no update
  server, and no request at all until a provider key is set.

### Notes

- GPL-2.0-or-later headers were already present and are confirmed. The remaining
  .org task is the marketing artwork (icon, banner, screenshots) for the SVN
  `assets/` directory — a design deliverable, not code; `readme.txt` names the
  screenshots it expects.

**Internationalisation pass — customer-facing and server surfaces (i18n)** — 2026-08-08

- All user-facing PHP strings are wrapped in `__()`/`esc_html__()` under the
  `storecrew` text domain (already largely in place; audited and completed),
  and `languages/storecrew.pot` is generated (99 strings) so translators have a
  catalogue. The text domain loads on `init`.
- The **storefront widget** is now translatable despite bundling no i18n runtime
  (it uses no `@wordpress/*` packages by design, rule 8): its own chrome — the
  aria-labels and the rate-limited / conversation-closed / message-too-long
  messages the merchant does not write — is translated server-side and delivered
  in a `strings` block on the uncached `/chat/boot` response. `%d` is substituted
  client-side. The merchant-configured appearance strings were already `__()`'d
  defaults.
- The widget is **RTL-safe**: `mount()` sets `dir` from `is_rtl()` on the shadow
  host, and the CSS is logical throughout (the one physical `margin` on the close
  button was made logical). A browser smoke test (`widget.spec.mjs`) forces
  `dir=rtl` and asserts the layout mirrors — the close button's margin flips
  side and no horizontal overflow is introduced.

### Notes

- Two i18n boundaries are deliberate. The **admin SPA stays English** for now —
  translating a React app that avoids `@wordpress/i18n` needs a bespoke
  server-provided string catalog, deferred as non-blocking for beta. And
  **model-facing strings** (tool descriptions, `ToolResult::error()` messages)
  stay English by design: the model reads them and replies in the *conversation's*
  language, so translating them to the merchant's locale would be wrong.

**Budget-host validation instrument + R-TECH-02 buffered-parse probe (R-TECH-03)** — 2026-08-08

- `tools/probe-budget-host.php`: a self-judging instrument for the one M1 row a
  local suite cannot close. It prints a **host capability report** — the kill
  window the host imposes, cron configuration, CLI-vs-web PHP, memory, Woo/HPOS
  — to be checked line-by-line against a real $5/mo host, and then runs a **full
  index under a forced-tight kill window** against a synthetic catalogue,
  driving the real `IndexJob` the way Action Scheduler would and killing it
  after ~one object per batch. It asserts the index still **completes** across
  ~150 kills with exact accounting (every object indexed once, monotonic cursor,
  a heartbeat per batch, stalls reaped) and reports throughput plus a
  real-catalogue cost estimate. Keyless (nothing embeds), snapshot-restoring,
  and deterministic — it detaches the job handlers and cancels each reschedule
  so a cron-triggered Action Scheduler runner cannot race it (a bug found while
  building it: the probe's own capability-report loopback was spawning the very
  runner that corrupted its accounting).
- Two **R-TECH-03 robustness fixes** the probe forced, shipped and probed in
  `verify-jobs`: a `storecrew_index_batch_seconds` filter to clamp the batch
  budget under a kill window tighter than `max_execution_time` reports (php-fpm
  `request_terminate_timeout`), and a guarantee that the **first object of every
  batch runs** so a slow object on a tight host cannot spin a zero-progress
  reschedule loop.
- **R-TECH-02 buffered==streamed, now probed.** The widget's SSE parse was
  extracted to `widget-app/src/sse.ts` (a transport-agnostic assembler the real
  `sendStream` consumes), and `tests/browser/sse.spec.mjs` transpiles that exact
  code and drives it under buffered / streamed / one-byte-at-a-time / every-
  split-offset / CRLF delivery — all reaching the same events and the same
  `done` payload. The assembler now also normalises CRLF/CR separators, so a
  proxy that buffers *and* rewrites line endings degrades to the buffered
  experience rather than a broken one. Closes the streaming criterion's
  buffering half at the parse layer; observing it on a real buffering host rides
  the budget-host run.

### Changed

- The widget SSE handling moved from an inline loop in `sendStream` to the
  shared `SseAssembler`; behaviour is identical for the `\n\n`-separated stream
  our server emits, with added CRLF/CR robustness. Widget bundle 6.18 KB gzip
  (well under the 45 KB budget).

**Adversarial suite v2 — the injection corpus (R-SEC-02, 12 § 10)** — 2026-08-08

- `tests/schema/verify-adversarial.php`: a **named** corpus of hostile content
  — product reviews, policy pages, order notes, product descriptions, each
  written to escalate a model into an unauthorised tool call — delivered
  through the real untrusted-input channel (a tool-role result, never system)
  and asserted to die at a boundary. The corpus is the artifact a security
  reviewer reads; the tags on each item name the channel it arrives on and the
  boundary that must catch it.
- **One corpus, two drivers, one set of assertions.** A *compliant* scripted
  model obeys every injection to the letter and always runs — no key, CI-able,
  and the proof that when an injection fully succeeds at the language layer the
  authority layer still refuses. All six boundaries fire under it: the identity
  gate, authority-is-not-model-supplied, one-identity-one-order confinement,
  writes-wait-for-a-human, invented tool, and the agent allow-list, across all
  four hostile channels.
- **The live driver decides for itself** (`STORECREW_ADVERSARIAL_LIVE=1`): the
  same corpus, the store's configured model, asserting no breach on any item
  and reporting how many attacks actually reached the boundary. Two findings
  shaped it. First, a well-aligned model *declines the injection on its own*,
  which is safe but leaves the boundary unobserved — so the customer's own
  message asks directly for the gated action, and the injection only tries to
  bypass the gate; the boundary then fires on authority grounds rather than on
  the model's reluctance. Live-observed 2026-08-08: `gemini-3.6-flash` called
  `order.lookup` on an **unverified** conversation exactly as the injected
  review demanded, and the identity gate denied it before execution — an
  attempt dying at a boundary, not at model discretion. Second, the free tier's
  per-model request bucket (09 § 3) drains after a call or two, so a run's later
  items 429; the suite treats a `provider_error`/`spend_cap`/`no_provider`
  outcome as a safe non-exercise, never a failure, and rotates which item gets
  the fresh-bucket slot (`STORECREW_ADVERSARIAL_START`) so a retry loop
  accumulates live coverage across quota windows.
- A breach is a forbidden *effect*, not a scary-looking answer: the suite
  watches whether the target tool actually ran and succeeded (a spy around the
  real `order.lookup`/`order.note`), so a denial, a pending-approval, and an
  allow-list refusal all read the same — zero successes for the forbidden
  target. No fixture is the merchant's: two throwaway orders, a negative
  conversation id, and cleanup by exactly that id.

**Onboarding flow (FR-ADMIN-02)** — 2026-08-08

- A `/setup` screen carrying the five-step path (key → sources → index →
  agents → widget) with every step's real control **inline**. The PRD's
  fifteen-minute time-to-value target is mostly spent finding the next
  control, so the flow is one screen rather than a tour of five others.
- **Step state is derived, never stored** (`Core\Onboarding`, served on
  `/bootstrap`): a provider resolves, a selection is on record, vectors exist
  with none pending, an agent is entitled and enabled, the widget is on. A
  stored "step N complete" marker is how a console congratulates a merchant
  whose crew cannot answer a question. One computation feeds both the payload
  and the screen; the copy lives only in the admin app.
- **Source selection is new capability, not new copy** — `POST
  /index/sources`, honoured by the walker, the pre-flight estimate, *and* the
  live `save_post` path, so a page published after an exclusion cannot walk
  back in one object at a time. Deselecting **purges** in the same request and
  reports how much it removed; storing the flag and leaving the rows would
  keep excluded content quotable while the console showed the exclusion as
  done.
- **Agent activation** — `GET`/`POST /agents` finally writes the
  `agent_configs.enabled` column the orchestrator has always read. Enabling an
  unentitled agent is a 403, not a row that never takes effect. `enabled` is
  written without bumping `version`, because standing an agent down changes no
  word of a prompt and `agent_runs.prompt_hash` is reconciled against that
  version. `CrewBar` gained a "Stood down" state so the switch is visibly
  connected to the board.
- `/index/estimate` is now consumed — the costly step leads with the object
  count, the passage count, and either a figure or an honest "we have no
  published rate for the model you chose" (R-COST-01).
- The Overview card names the blocking *step* and links into the flow; a
  **Finish setup** rail entry appears only while the flow is unfinished.
- **First activation opens the flow.** `Activator` sets a one-shot flag only
  when the plugin has never been activated here; `AdminPage` consumes it on
  `admin_init` (priority 20, after the migrator) and redirects. The flag is
  spent before anything is decided, so a request that cannot redirect — bulk
  activation, network activation, wrong capability — still spends it: a
  redirect that can retry is a redirect that can loop. Verified in a browser
  firing from `plugins.php` and not firing on the next load.
- The `index` step completes on **one vector, not a drained queue**. Embedding
  scales with the catalogue, so `pending === 0` reported a 5,000-product store
  unfinished for an hour over work the merchant cannot hurry — against the
  fifteen-minute exit criterion, which is about their time. The remainder is
  stated in words on the step rather than dropped to buy the tick.
- 29 new PHP probes and 4 browser probes; nine suites green in both orders
  (736 assertions), admin console green across seven screens in both themes.

### Fixed

- `verify-knowledge` restores the model policy it borrows via
  `register_shutdown_function`. A fatal between the write and the cleanup block
  left a configured store carrying the suite's fake embedding provider, and the
  next run snapshotted the poison and put it back — snapshot-and-restore does
  not help if the restore is the thing that gets skipped.
- The source-selection probe no longer runs against the merchant's own index.
  A first draft deselected `product` from inside `verify-rest` and deleted 47
  real chunks; the purge is now probed in `verify-knowledge` against a
  synthetic source type, and `verify-knowledge` optimises the FULLTEXT table on
  the way out like `verify-repositories` already did.

---

### Added

**Timed incremental delivery, verified live (FR-CHAT-02)** — 2026-08-08

- `tools/probe-streaming-delivery.php` — the standing measurement for the
  half of the streaming criterion no scripted probe can reach. Plain-PHP CLI
  against the public chat surface over real HTTP: opens a session, streams
  one turn, timestamps every network chunk as cURL delivers it, judges its
  own verdict from the raw timeline, and closes the conversation it opened.
  A buffered response is indistinguishable from streaming in every test that
  ignores time; this one measures nothing else.
- Observed on the wire: 9 deltas at 9 distinct network arrivals over 609 ms,
  reassembling byte-for-byte to the `done` payload, through nginx, php-fpm,
  and every guard. Re-run the next attempt cold: passed first try. The
  remaining half of the criterion is the buffering-host exercise, which
  rides the budget-host validation row (R-TECH-03).
- Getting the pass took eight 429'd attempts across two keys, each an
  unplanned live rehearsal of the failure path (a sentence in `done`, run
  `failed` carrying the provider's code). Finding on record in 09 § 3: the
  free tier's `generate_content_free_tier_requests` bucket (limit 20) is
  per-model and opaque — `gemini-3.5-flash` routing kept answering while
  `gemini-3.6-flash` chat refused, a brand-new key's first-ever request
  429'd, and the "retry in Ns" hint was wrong in both directions. Support
  should read the model in the quota message and treat intermittent 429s as
  bucket contention, not a broken key.

---

### Added

**Streaming (FR-CHAT-02)** — 2026-08-07

- `StreamingChatProviderInterface` — an *addition* to the provider contract,
  never a change: third-party providers keep working, and `stream()` returns
  the same assembled response `chat()` would, so every runner decision reads
  the assembly. Streaming changes when pixels appear, never what is decided
  (12 § 10 — probed: a rate-limited streaming request is refused before any
  event starts).
- `CurlSseClient` — the one sanctioned raw-cURL site. `wp_remote_post`
  buffers by design; this honours the WordPress proxy constants and degrades
  via `available()` on a cURL-less host. Gemini implements the interface and
  declares the capability only when the transport exists; the three
  providers that had declared `streaming: true` with nothing behind it are
  corrected to `false`.
- SSE negotiation on the messages route by `Accept` header, after every
  guard; `delta` events, then a `done` event carrying exactly the JSON
  path's payload — one widget contract however the answer travels. A
  buffering host delivers the same events in one piece, which the widget
  parses identically: R-TECH-02's fallback by construction.
- Widget token rendering: deltas paint as plain text, the finished reply is
  re-rendered through the Markdown path, and screen readers hear the
  completed message once via a visually-hidden status region rather than
  stuttering through fragments (FR-CHAT-04).
- 22 probes across the runner and transport layers; live `event: delta`
  frames observed on the wire from real Gemini; the provider-failure path
  exercised live *through* the SSE transport — the customer got a sentence
  in `done` and the conversation escalated.

### Fixed

- **Gemini separates SSE events with `\r\n\r\n`.** The parser split on
  `\n\n` only, so the live stream "succeeded" with zero events parsed —
  invisible to every scripted probe, which naturally wrote tidy `\n\n`.
  Only a live call finds this class of bug; this is the fourth entry in
  that catalogue.
- A live-only quota fact for support's benefit: **the Gemini free tier
  meters `streamGenerateContent` separately from `generateContent`** — a
  key can chat but not stream, and the run record's 429 says which.

### Added

**M1 hardening — nine of fifteen exit criteria closed** — 2026-08-07

Six work items in one sweep of 14 § M1, each probe-tested on landing:

- **Retention enforced** (04 § 11 → Implemented): all four windows prune
  from the hourly sweep, batched at 500. Conversation pruning cascades to
  messages, runs, and tool calls; **pending approvals are exempt from any
  window**; sub-floor settings clamp up rather than silently off.
- **GDPR exporter/eraser** registered with core's personal-data tools.
  Erasure severs `customer_id`, the proven order, and the session binding —
  no surviving cookie resumes an erased thread — and blanks content while
  rows and counters survive. Export excludes operator notes. Reach is
  bounded by the identity model: anonymous conversations are not
  attributable by email, deliberately.
- **Escalation email** (the push half of FR-SUPPORT-07): one email per
  escalation — the transition rings, further failed turns do not — linking
  into the inspector, never carrying the customer's words.
- **Failover executes** (FR-AI): one switch to the configured fallback,
  continuing from the request state at failure so an executed tool never
  runs twice; both attempts on the run record; both-dead is terminal after
  one switch. The settings API had been silently stripping submitted
  `fallback` keys — fixed, so the failover is configurable at all.
- **Both dormant `agent_configs` surfaces consumed**: merchant house rules
  compose additive-only after every shipped guardrail behind a subordinating
  frame (probed against "Ignore the price rule"); per-agent model policy
  resolves ahead of the global, with a broken override degrading to the
  global resolution rather than to a failed turn. FR-AGENT-09's Gate 3
  rescope is retired.
- **`product.lookup`**: exact SKU resolution with live price/stock. Unknown,
  draft, and private SKUs share one indistinguishable miss (no oracle for
  unpublished catalogue); out-of-stock is found and named, not hidden. The
  recall harness gains identifier fixtures scored against this path — 3/3,
  semantic path unconsulted.
- **Pro ships `uninstall.php`**: exactly its licence options, nothing the
  free plugin owns; harness probes both directions.
- **`composer check`**: phpcs (WPCS tuned to the documented conventions —
  six genuine defects fixed rather than excluded), phpstan level 5 clean
  (one honest lie found: `Agent`'s own docblock), and
  `tools/check-invariants.php` — noGlobalWpdb with its carve-outs,
  noProReferenceInFree, and parse-safety, each self-testing by violating
  itself once. phpunit was declared and used by nothing; removed.

Still open in M1: streaming (FR-CHAT-02), the onboarding time-to-value
measurement, adversarial suite v2, budget-host validation, i18n, and the
.org compliance pass.

### Verified end to end, live

**First real customer conversation** — 2026-08-07

Five turns through the storefront widget against live Gemini
(`gemini-3.6-flash` chat, `gemini-3.1-flash-lite` routing,
`gemini-embedding-001` embeddings, 67/67 chunks embedded):

1. A product question routed to **Sales**, ran `product.search`, and answered
   from the catalogue.
2. A policy question routed to **Support** and grounded in the indexed returns
   policy.
3. An order question with no identity was refused with the next step — and
   leaked nothing.
4. A wrong email did not verify.
5. The right email verified and read the real order **in the same turn** — one
   run, two tool calls (`identity.verify` then `order.lookup`), which is the
   mid-turn identity listener working as designed. Status, items, and total came
   from live Woo data.

The first attempt failed usefully, twice, and both failures were handled as
designed — a sentence to the customer, an escalation with the reason recorded:

- The key was free-tier, which has **zero quota for `gemini-2.5-pro`** — every
  chat call 429'd.
- `gemini-2.5-flash` then 404'd: **the 2.5 generation is refused for keys
  created after the 3.x line shipped** ("no longer available to new users").
  Only a live call finds this class of failure.

### Fixed

**Gate 4 remediation, first pass** — 2026-08-07. One code change; the rest of
the gate's findings wait on decisions and are recorded, unfixed, in
`docs/reviews/gate-4-review.md`.

- **The Inbox now answers a click it cannot honour.** Deciding an approval that
  had already been decided returned a 409 the client never rendered, so the
  merchant pressed Approve on a stale card and *nothing visibly happened* — no
  message, no removal, no refetch. The card now carries the server's own
  sentence with an alert edge and offers a refresh, rather than vanishing on
  its own: a row that silently disappears answers the click with as little as
  saying nothing did. Also scoped the busy state to the card being decided —
  one pending mutation was greying out every card's buttons.
- Documented, not fixed: the capability manifest is computed on every bootstrap
  and consumed by nothing — no component reads `catalog`, no navigation reads
  the routes' `icon`/`order`/`inMenu` — so `CrewBar` hardcodes premium's agents
  and their copy, and every screen premium registers is reachable only by
  typing its hash. Fourth instance of the shape Gates 2 and 3 each found once.
- 10 § 2's entitlement keys matched no registered slug (`agents.marketing` for
  `agent.marketing`, and five more). Corrected in the document before anything
  was built from it: an unknown slug evaluates as not-entitled, so a licence
  server written to the old spec would have issued a valid signed snapshot
  granting a paying customer nothing, silently.

### Added

**Gate 3 remediation** — 2026-08-07. The review found no security defect, but
three capabilities the architecture documents described as working that no
production path exercised:

- **`agent.handoff`, the trigger FR-AGENT-03 never had.** `Orchestrator::handoff()`
  had zero callers, so Sales' guardrail was promising customers a transfer that
  could not happen. The model now calls a tool; it validates the target against
  the orchestrator's own available-agents list, rejects self-handoff and empty
  notes, and fires `storecrew_handoff_requested`. A conversation-scoped listener
  performs the transfer *after* the current run, capped at one hop per customer
  turn. The receiving agent's allow-list and the executor apply unchanged — a
  handoff moves routing, never privilege.
- **`agent_runs.cost_known` (Migration002).** `Pricing` reported unknown cost
  honestly and the flag then died at the row boundary, so an unpriced model
  showed in the inspector as a *free* call — the fabricated zero the pricing
  rule forbids, arriving by omission. Also the migration machinery's first
  firing outside a probe.
- **Surfaced products reach the shared context** via
  `storecrew_products_surfaced`, so a handoff carries what the customer was
  already shown — ids, not names, so the receiving agent reads details live.

### Fixed

- **Refusals and provider failures now meter what they burned.** Only answered
  and budget-exceeded turns metered; refused and failed ones spent real tokens
  that never reached the counters, so SpendGuard and the dashboard under-counted.
- **An invented tool name resolves to `failed`**, not left pending forever. The
  inspector showed a hallucinated call as awaiting approval.
- **Run `error_code` keeps the provider's own code** (`429:RESOURCE_EXHAUSTED`).
  The transport extracted it and the runner discarded it, storing only the HTTP
  status — the difference between waiting and reconfiguring.
- **`get_json()` retries like `post_json()`.** It is the credential-check path
  for four of five providers, so a transient 503 while verifying a key read to
  the merchant as "your key was rejected".
- **`SpendGuard::status()` no longer fires the breach action.** It computed
  `blocked` via `allows_call()`, whose `warn` path emits
  `storecrew_spend_cap_exceeded` — so every `/health` poll past the cap emitted
  a spend event.
- **`OpenRouterProvider` no longer defaults to a `gemini-2.5` id** — the
  generation `GeminiProvider` already records as dead for new keys.
- **`verify-providers` was deleting the merchant's real provider keys.** Its
  cleanup forgot `provider.*.key` by name with no snapshot, silently
  unconfiguring a configured store on every run — the secrets edition of the
  model-policy bug below. It now snapshots `storecrew_secrets` *and*
  `storecrew_data_key` (rotation replaces the key the ciphertexts need) and
  restores both.
- **"Unconfigured store" probes assumed the state instead of building it.** The
  `canEmbed`/degraded probes in `verify-rest` and the blocked-embedding probe in
  `verify-jobs` only passed because another suite had just wiped the keys — and
  once a real key survived, the jobs probe ran the embed job against it: a live,
  billable call from inside a test. Both now hide keys for the probe's duration
  and restore.
- **Four suites deleted the merchant's configuration on every run.**
  `verify-agents`, `verify-rest`, `verify-knowledge`, and `verify-chat` all
  write the live model-policy option (and chat/spend settings) and cleaned up
  with `delete_option` — so running the plugin's own tests on a configured
  store wiped its provider assignments. Invisible until now because no site had
  ever *been* configured. All four snapshot at start and restore at cleanup,
  and `verify-chat` asserts the restoration.
- `GeminiProvider::default_models()` now lists the 3.x line. Offering 2.5-era
  ids meant a fresh install's settings screen proposed models that fail on the
  first real turn.

---

### Added

**The storefront chat surface** — 2026-08-07

The first customer-facing surface in the product. Everything before this could
answer a question; nothing could be asked one.

- `src/Chat/ChatService.php` — the path from a typed message to a persisted
  answer. History is rebuilt from the database on every turn and never read from
  the request: a client that could supply its own transcript could plant an
  assistant turn saying identity had been verified.
- `src/Api/Rest/Controllers/ChatController.php` — four public routes
  (`/chat/boot`, `/chat/session`, `/chat/{uuid}/messages`, `/chat/{uuid}/close`),
  the only unauthenticated routes in the plugin. **The uuid is an address, not a
  credential** — reading or writing a conversation requires a session token the
  server issued, and an unknown uuid and an unowned one return the same 404 so
  the API cannot confirm that a given conversation exists.
- `src/Chat/Session.php` — only a `sha256` digest of the token is stored, so a
  dump of the conversations table hands an attacker nothing they can present.
- `src/Chat/RateLimiter.php` — fixed windows per session *and* per address
  (FR-CHAT-06). Either alone is trivially beaten: a session limit by discarding
  the cookie, an IP limit by putting a school or a mobile carrier's NAT behind
  one counter. Addresses are stored as the same salted hash the audit log uses.
- `src/Agent/Tools/IdentityVerifyTool.php` — order number plus the email on that
  order (FR-SUPPORT-01). The executor already told customers to supply exactly
  this and nothing received it; `order.lookup` was unreachable from a storefront
  until now. A wrong email and an unknown order return the *same* sentence —
  distinguishing them makes an oracle for which order numbers exist — and
  attempts are capped per conversation.
- `src/Chat/Widget.php` + `widget-app/` — the widget itself. Vanilla TypeScript
  in a shadow root, **5.3 KB gzipped** against the 45 KB budget. The page carries
  a single `async` script tag and the REST root; it carries **no nonce and no
  conversation state**, because a WooCommerce storefront is page-cached and
  anything printed into the document is served to the next thousand visitors.
  Configuration comes from `/chat/boot`, which is not.
- Shortcode `[storecrew_chat]` and a `storecrew/chat` block (FR-CHAT-07). The
  block's editor script is hand-written against the `wp.blocks` globals — no
  build step, and no `@wordpress/*` package enters the dependency tree.
- Settings gained an **On the storefront** panel: the on-duty switch, launcher
  and panel wording, accent colour, corner, and floating-versus-manual placement.
  The switch is disabled until a chat model is set, because a widget that appears
  and then cannot answer is worse than no widget.
- `tests/schema/verify-chat.php` — 83 assertions, most of them a guard being
  deliberately violated.

Verified in a real browser against a scripted provider: mount, keyboard open,
focus into the box, a full turn, Markdown rendering, conversation surviving a
page reload, Escape returning focus to the launcher, and the panel going
full-screen on a phone in dark mode. Two bugs surfaced that no PHP test could
have (below).

### Fixed

- **A model's most common answer shape rendered as literal hyphens.** The
  Markdown renderer classified a whole block as either a list or a paragraph, so
  "Here is what I can tell you:" followed by three dashed lines — which is what
  models actually produce — fell through to the paragraph branch and printed the
  dashes. Lines are grouped into runs now.
- **The chat session cookie is `SameSite=Lax`, not `Strict`**, so a customer
  arriving from an order-confirmation email is still recognised.
- `ConversationRepository::escalate()` added, distinct from
  `close( STATUS_ESCALATED )`. Escalation is a request for help *during* a
  conversation; stamping `closed_at` would have taken the thread out of the
  customer's hands at the exact moment it got difficult.

### Changed

- The support agent declares `identity.verify`, and its guardrail now names the
  tool rather than asking the model to "confirm" identity conversationally.
- `SettingsController` reads and writes the chat settings block.

### Security

- Retrieved and generated text reaches the DOM through `document.createTextNode`
  and `createElement` only. Nothing in the widget ever assigns `innerHTML` — the
  text being rendered was written by a model that has been reading indexed
  product descriptions and customer reviews all along. Bare URLs become links
  only after the parsed scheme is checked, and carry
  `rel="noopener noreferrer nofollow"`.
- A live provider failure during the browser run proved FR-CHAT-03 rather than
  breaking it: the exception was contained, the customer got a sentence, and the
  conversation was escalated with the reason recorded.

### Known gap

**FR-CHAT-02 (streamed responses) is still unmet.** The widget uses the buffered
path everywhere, not only on hosts that buffer. Streaming needs SSE, which
`wp_remote_post` cannot do — it needs raw cURL with a write callback, and a
streaming variant of the provider interface.

---

### Added

**The admin application** — 2026-08-07

- `src/Core/Admin/AdminPage.php` — the host page. Registers a top-level menu
  under `storecrew_manage` (not `manage_options`, so a shop manager is not
  locked out), renders a single mount point, and enqueues the bundle on its own
  screen only. Admin notices are removed on this page: the app owns the whole
  viewport, and other plugins inject notices freely.
- `admin-app/` — React 19 + TypeScript + Vite + Tailwind 4, with **no
  `@wordpress/*` packages of any kind**. React is bundled rather than borrowed
  from core, whose version moves with each WordPress release.
- Six screens: Overview, Crew, Knowledge, Inbox, Settings, and a conversation
  detail view. Routing is `HashRouter` — `admin.php?page=storecrew` is the only
  URL WordPress knows about.
- Premium routes render from the bootstrap manifest, so the free plugin
  displays an upgrade panel for screens it cannot load without ever knowing
  what they are.
- Built bundle: 301 KB raw / 93.5 KB gzipped, inside the PRD's 250 KB budget.
- `tests/schema/verify-admin.php` — 32 assertions covering menu registration,
  capability, asset scoping, the bootstrap payload, and every endpoint the app
  calls on first paint.

The console is built as a shift board rather than an analytics dashboard:
status is the primary information, numbers are secondary, and the vocabulary is
on duty / needs you / needs setup. No webfonts — a plugin that pulls fonts from
a CDN leaks every admin page view to a third party and breaks offline.

### Fixed

**Three bugs the admin work surfaced** — 2026-08-07

- **Tailwind lost every cascade fight with wp-admin.** Layer order is consulted
  *before* specificity, and unlayered declarations beat layered ones at any
  specificity. WordPress admin CSS is unlayered; `@import 'tailwindcss'` puts
  every utility in `@layer utilities`. So `body { color: #3c434a }` beat the
  app's own text colour, and `a { color: #2271b1 }` repainted every link admin
  blue. Invisible in light mode, because WordPress's dark-grey-on-white looks
  like what was intended — it only surfaced in dark mode, where surfaces
  flipped and text did not. Utilities are now emitted unlayered and important,
  and the reset is scoped to `#storecrew-root` at ID specificity, which is what
  it takes to outrank `input[type='text']:focus`.
- **Tailwind preflight was being shipped into wp-admin globally.** It resets
  `*`, `button`, `ul`, and friends on a page that also contains the admin menu
  and toolbar. Replaced with a reset scoped to `#storecrew-root`. The
  stylesheet got smaller as a result.
- **A knowledge base with no embedding model reported itself fully ready.**
  `Indexer::health()` passed the resolved model straight through to
  `count_embedded()`, whose `''` means "do not filter by model" — correct for
  counting rows, wrong for answering "is this searchable". A fresh install
  showed *62 of 62 ready* while nothing could answer a question, because with
  no model configured the *query* cannot be embedded either. Health now reports
  those vectors as stranded rather than ready.

### Changed

- `AdminPage` is registered unconditionally rather than behind `is_admin()`.
  Both hooks it attaches are admin-only already, so the guard gated a gate —
  and it made the menu unreachable under WP-CLI, where `is_admin()` is false,
  hiding menu bugs from the only harness that could catch them.
- The integration shim gained `is_admin()`. The harness caught the kernel
  calling an unshimmed function, which is exactly its job.

**First live run, and the FR-KB-09 measurement** — 2026-08-07

- `tools/seed-demo-catalogue.php` — 30 products with descriptions written so a
  shopper's words differ from the catalogue's, which is the only way retrieval
  quality is measurable at all.
- `tools/measure-recall.php` — the FR-KB-09 harness. 23 shopper-phrased fixture
  questions, recall@3 and recall@5, and a sweep over the fusion weight.
- Embedding dimensionality is now requestable and configurable
  (`storecrew_embedding_dimensions`, default 1536). Gemini defaults to 3072 —
  12 KB per vector — so this is the single largest lever on index size.
- Index health now reports the configured model, width, and a **mismatched**
  count: vectors embedded by a different model or at a different width look
  perfectly healthy while scoring 0.0 against every query.

### Fixed

- **`index_object()` never called `mark_indexed()`.** Sources stayed `pending`
  forever, `chunk_count` stayed 0, `needing_index()` never drained, and the
  dashboard would have reported an index that never finished.
- **Gemini tool calls failed on the continuation turn.** Newer models attach a
  `thoughtSignature` to each `functionCall` and reject the follow-up with a 400
  unless it is echoed back verbatim. `ToolCall` now carries an opaque
  provider signature. Only a live call could have found this.
- **`gemini-embedding-001` is 3072 dimensions, not the 1536 the schema sizing
  assumed** — double the storage. `text-embedding-004` was in the default model
  list and 404s; it is not offered on v1beta.
- Chunks embedded by a different model or at a different width are now treated
  as needing embedding, so changing either is self-healing rather than a silent
  corruption of the whole index.

### Changed

- **The default fusion weight is now 1.0 — the lexical arm no longer
  contributes to ranking.** Measured over 23 fixtures: dense 0.80 (the previous
  default) scored **recall@3 0.83, failing the 0.88 bar**; 0.90 scored 0.91;
  1.00 scored 0.96. Recall improves monotonically as lexical influence falls.
  The cause is the normalisation — the lexical score is scaled against the best
  match *within the candidate set*, so the top keyword hit always scores 1.0
  however weak the match, which is how "warm hat for winter" returned a
  wholesale policy page. The counter-argument that lexical rescues exact
  identifier lookups was tested and did not hold.
- **Retrieval is now adaptive.** Below 2,000 chunks every query gets a full
  dense scan; above it the lexical prefilter is used. That threshold is
  measured, not guessed: cosine over a 1536-dimension vector costs ~90 µs, so a
  full scan is 91 ms at 1,000 chunks, 454 ms at 5,000, and 13.6 s at 150,000.
  The two-stage prefilter scored **0.80 recall@3 against pure dense's 1.00** on
  the same corpus — its weakness is structural, since MySQL FULLTEXT cannot
  match "warm hat for winter" to a product called "Beanie" at any candidate
  limit. Large catalogues still need the external vector index R-TECH-01 named.

### Verified end to end

Two live turns against Gemini: routing picked Sales for an ear-warmer question
and Support for a returns question, both tools executed, and both answers were
grounded in real catalogue and policy data with live prices.

---

### Added

**Agent framework** — 2026-08-07

- Provider tool-calling across all three families. Anthropic uses `tool_use` /
  `tool_result` content blocks with results on a *user* turn; OpenAI uses a
  `tool_calls` array with a dedicated `tool` role and arguments as a JSON
  *string*; Gemini uses `functionCall` / `functionResponse` parts, assigns no
  call ids at all, and matches results back by tool *name*. Normalised once in
  `Message` rather than at three call sites.
- `ToolInterface` — every tool declares read-or-write, the capability required,
  and whether identity must be proven. The model supplies only which tool and
  what arguments; both are untrusted.
- `ToolExecutor` — **the security boundary**. Authorisation runs in a fixed
  order: tool exists → not disabled → capability held → identity proven → write
  approved → filter veto. `storecrew_tool_authorized` runs last and its return
  is ANDed with the decision already made, so **no filter can grant a permission
  the earlier checks refused** (R-SEC-01).
- `TurnBudget` — three independent ceilings (tool calls, tokens, wall-clock)
  because the failure modes differ. Exhaustion is a recorded `budget_exceeded`
  run status, not a silent short answer (FR-AGENT-06).
- `SharedContext` — structured handoff state rather than a concatenated
  transcript, so a receiving agent inherits conclusions instead of re-deriving
  them (FR-AGENT-03).
- `AgentRunner` — the tool loop. Retrieved content enters as user-role content,
  never as system: a product description is data to reason about, not an
  instruction to obey.
- `Orchestrator` — routes on the cheap `routing` model, and falls through to a
  default agent whenever classification fails. A classifier outage must not cost
  a customer their answer (FR-AGENT-02).
- Four tools: `product.search` (reads live price and stock — the values
  deliberately absent from the index, and drops anything unpurchasable per
  FR-SALES-02), `policy.lookup` (says "not published" rather than letting the
  model invent a returns window), `order.lookup` (identity-gated, and refuses
  orders other than the one verified), `order.note` (the first write, so the
  first requiring approval).
- Sales and Support agents with narrow tool allow-lists — Support cannot search
  the catalogue and Sales cannot touch orders, so an injection that oversteps
  fails at the agent boundary before reaching the executor.
- 68 assertions, driven by a scripted provider.

### Security

- A merchant-edited persona cannot strip the guardrails. Mission and constraints
  are appended *after* the persona, so FR-AGENT-09 cannot be used to disable
  FR-SALES-09.
- Verification proves *an* identity, not *every* identity. `order.lookup`
  refuses an order other than the one confirmed, so verification cannot become a
  skeleton key to the order table (R-SEC-02).

### Changed

- Tools are registered as **factories, resolved on first use and memoised** —
  the same treatment given to REST controllers and job handlers. Constructing
  retrieval and its repositories on every storefront page load, for a visitor
  who never opens the chat widget, is waste. This is the third time eager
  resolution has been caught by the database-free integration harness.

---

### Added

**REST API** — 2026-08-07

- `storecrew/v1` namespace, 18 routes across 7 controllers, contributed through
  a `ControllerRegistry` (`storecrew_register_rest_controllers`). Premium
  registers into the *same* namespace rather than claiming its own, so the admin
  SPA has one API surface and never has to know which plugin owns a route.
- `RestController` base — **every route must declare a capability**. There is no
  default-allow path, so forgetting to think about permissions produces a locked
  route rather than an open one. Feature gating is re-checked server-side; the
  SPA's capability manifest is a rendering hint, and editing it in the browser
  yields a 403 (FR-DIST-09).
- `GET /bootstrap` — everything the SPA needs on first paint in one request,
  including onboarding state. The `canEmbed` flag exists because an
  Anthropic-only install has working chat and cannot index anything, and
  discovering that when indexing silently produces nothing is a bad first hour.
- `GET /health` — environment, queue, index, spend, and encryption key source.
  Every "running" state is judged by **heartbeat rather than stored status**,
  because the failure mode merchants hit is a job that died hours ago while the
  dashboard still reports it as live (FR-ADMIN-08).
- `GET/POST/DELETE /providers/*` — key management. **A stored key is never
  readable through the API again**; responses carry a masked hint only. The
  audit log records that a key was saved, never the key.
- `GET/POST /settings` — model policy and spend cap. Rejects a provider assigned
  to a task it cannot perform, so a bad policy fails at the screen where it was
  set rather than hours later in a background job.
- `GET/POST /index/*` — status, pre-flight cost estimate, start, cancel, and a
  manual embedding drain for after a key is fixed or a cap raised.
- `POST /knowledge/search` — runs retrieval exactly as the agent would and
  reports the strategy, so "the agent gave a bad answer" becomes falsifiable
  (FR-KB-10). POST rather than GET because it embeds the query and therefore
  costs money.
- `GET /conversations/*` — the conversation inspector: turns, runs, retrieval
  traces, and every tool call (FR-ADMIN-04). Addressed by **uuid**, never by
  auto-increment id, so conversation history cannot be enumerated by counting.
- `GET/POST /approvals/*` — the pending-write queue (FR-ADMIN-06).
- 89 assertions dispatched through the real `WP_REST_Server`, so permission
  callbacks and argument validation are exercised rather than bypassed.

### Changed

- REST controllers are registered as **factories, not instances**. The
  registration window still closes at `plugins_loaded` 20 so the contributed set
  is final, but construction defers to `rest_api_init` — building seven
  controllers and the ten repositories behind them on every storefront page load
  is pure waste, and it broke the deliberately database-free integration
  harness. Same root cause as the eager job-handler resolution fixed earlier.

---

### Added

**Background job runner** — `9e09b07`, 2026-08-07

- `Scheduler` wrapping Action Scheduler, scoped to the `storecrew` group so a
  deactivation cancels our work without touching another plugin's queue. Every
  call is guarded; a site without WooCommerce degrades to "background work
  unavailable" rather than fataling.
- `Deadline` sizes a batch from the host's own `max_execution_time` rather than
  a fixed number, and jobs stop *before* starting work they cannot finish. A job
  that chooses to stop leaves a clean cursor; a job that gets killed does not
  (R-TECH-03).
- `IndexJob` — resumable full-index walker. The cursor encodes both extractor
  and position, because "resume from product 4,210" is meaningless once the walk
  has moved on to pages. Refuses to start a second concurrent run.
- `EmbedJob` — drains pending embeddings, backs off on transient provider
  failure, and stops entirely (announcing `storecrew_embedding_blocked`) when
  the reason will not resolve on its own.
- `ReindexJob` — consumes `storecrew_queue_reindex`. Deduplication collapses a
  bulk edit's 500 save hooks into one queued job; an unchanged content hash
  skips queuing an embedding pass at all.
- `MaintenanceJob` — hourly sweep reaping heartbeat-dead index runs and agent
  runs, abandoning stale conversations, and pruning the audit log. Audit
  retention has a 180-day floor so it cannot be quietly disabled.
- 51 assertions against the site's real Action Scheduler.

### Fixed

- **Extractor pagination never advanced past the first batch.** `ids()` asked
  `WP_Query` for the first N ids and *then* filtered to those above the cursor,
  so once the cursor passed that page every batch filtered to nothing, the
  walker concluded the extractor was exhausted, and a full index stopped
  silently after ~20 objects — no error, just a mostly-missing catalogue. The
  cursor now reaches the database through `posts_where`. `9e09b07`
- **`Scheduler::cancel()` missed every action carrying arguments.** Action
  Scheduler only takes its cancel-by-hook fast path when no group is supplied;
  passing one falls through to an args-exact loop, so an empty array cancelled
  only actions that had no arguments. `9e09b07`

### Changed

- Job handlers resolve lazily from the container. Registering them eagerly
  constructed every job and therefore every repository on each request, which
  broke the deliberately database-free integration harness and would have made a
  storefront page load pay to build jobs it will never run. `9e09b07`
- Two assertions that asserted the literal `true` replaced with real ones: audit
  retention cannot be driven below its floor, and double-scheduling the sweep
  does not duplicate it. `9e09b07`

---

### Added

**Knowledge-base pipeline** — `1c45b3f`, 2026-08-07

- `ExtractorInterface` + `ExtractorRegistry` (`storecrew_register_extractors`).
- `ProductExtractor` — **enforces FR-KB-08**. Price, sale price, stock status,
  and stock quantity never enter the index, so the agent cannot quote a stale
  price and a stock edit produces a byte-identical hash that skips re-embedding
  entirely. Also excludes catalogue-hidden and draft products.
- `PostExtractor` — pages and posts, for policy and FAQ grounding
  (FR-SUPPORT-04). Password-protected pages are never indexed.
- `Chunker` — splits on paragraph then sentence boundaries with overlap. Keeps
  the packing target **below** the embedding ceiling: token counts are estimated
  from character length and can be wrong by a third, so target-equals-ceiling
  means one underestimate produces a chunk the API rejects.
- `Indexer` — extract → hash → chunk → embed → store. Chunking and embedding are
  separate stages because one is cheap and local and the other billable and
  remote. A response with the wrong vector count or ragged dimensions is refused
  outright.
- `Retriever` — embeds queries with the **query-side** task type (FR-KB-06) and
  degrades to keyword-only when no embedding provider is configured, reporting
  the degradation rather than silently answering worse.
- Pre-flight cost estimate before indexing starts (R-COST-01).
- 53 assertions, including against a real WooCommerce product.

### Fixed

- A scripted edit passed an `ExtractorRegistry` into `ModelPolicy`'s factory,
  which takes only providers. Caught before commit. `1c45b3f`

---

### Added

**AI provider layer** — `0c829e2`, 2026-08-07

- Five providers behind one interface: Anthropic, OpenAI, Gemini, OpenRouter,
  DeepSeek.
- `Capabilities` — providers declare what they can actually do rather than
  pretending uniformity. **Anthropic rejects `temperature`/`top_p`/`top_k` with
  a 400** and has **no embeddings endpoint**, so chat and embedding are separate
  interfaces and an Anthropic-only install resolves embeddings to `null`.
- `GeminiProvider` implements FR-KB-06's query-vs-document embedding task types
  — using the document type for a query costs recall without erroring anywhere.
- `SecretStore` — envelope encryption. Secrets are encrypted with a data key
  wrapped by a master, so rotating the master re-wraps one blob and leaves every
  secret intact (FR-AI-03). Data-key rotation refuses to proceed at all if any
  secret fails to decrypt first, because a partial rotation would silently
  destroy keys.
- `HttpClient` on the WordPress HTTP API with retry, jittered backoff, and
  `Retry-After` honoured. No bundled client library.
- `ModelPolicy` — per-task model selection (FR-AI-02). `Pricing` — cost
  estimation that reports **unknown rather than zero** for unpriced models.
  `SpendGuard` — hard monthly ceiling (FR-AI-06, R-COST-01).
- 73 assertions, no network calls.

### Changed

- Extracted `HttpClientInterface` mid-build. The providers depended on the
  concrete client, which made request shaping untestable without live API calls
  — and request shaping is where a mistranslated system prompt or role name
  becomes a wrong answer rather than a crash. `0c829e2`

---

### Added

**Repository layer** — `f34afea`, 2026-08-07

- Ten repositories over the eleven tables. Nothing else touches `$wpdb`, which
  is what keeps the vector storage format swappable.
- `Vector` — packed float32 codec and cosine similarity. Returns `0.0` for
  mismatched dimensions rather than throwing, because a corpus mid re-embed
  legitimately holds rows from a previous model.
- Conversations revoke identity verification when the customer changes, so a
  shared device cannot inherit someone else's order access.
- Unconfigured tools default to requiring approval (FR-AGENT-05).
- Index runs judge liveness by heartbeat rather than status, because a killed
  process leaves `status = running` forever.
- Audit stores a salted IP hash, never a raw address.

### Fixed

- **`LEXICAL_FLOOR` was 3**, so any query matching one or two chunks fell
  through to a full dense scan — backwards, because a query matching two chunks
  is *precise*, not failed, and the fallback would have fired constantly on
  exactly the queries the two-stage design exists to serve cheaply. Now 1.
  `f34afea`

---

### Added

**Database schema** — `bc7c528`, 2026-08-07

- Eleven tables, all InnoDB / `utf8mb4`, created by a forward-only migration
  runner that runs on `admin_init` rather than activation — a fatal mid-schema
  during activation leaves a site with no way to retry, and updating by file
  upload never fires the activation hook at all.
- Migration lock uses `add_option` rather than a transient: `option_name`
  carries a unique index so the INSERT either wins or fails atomically, where a
  transient can be served from a shared object cache and handed to two requests.
  Locks older than the TTL are broken rather than honoured.
- `uninstall.php` — drops only what this plugin created, and only when the
  merchant opted in.
- 31 assertions against real MySQL, including a dbDelta drift probe.

### Changed

- Three columns renamed from the schema document and the document corrected:
  `cursor` → `cursor_position` (reserved in MySQL), `authorization` →
  `auth_mode` (reserved in the SQL standard), and `knowledge_sources` keyed on a
  hashed `source_key` rather than a prefixed composite unique, which `dbDelta`
  handles unreliably. Renamed rather than escaped — a column that needs
  backticks forever is a trap. `bc7c528`

---

### Added

**Platform foundation** — `1fc9cf2`, 2026-08-07

- Plugin bootstrap with PHP / WordPress / WooCommerce version guards. Bootstrap
  and `Requirements` stay PHP 5.6-parseable so an unsupported host sees a notice
  rather than a white screen.
- HPOS and Cart & Checkout Blocks compatibility declared (FR-CORE-02/03).
- Hand-written PSR-11 container with circular-dependency detection.
- `ExtensionApi` — the entire add-on contract. Deterministic registration
  window: kernel at `plugins_loaded` 5, `storecrew_api_ready` at 10, registries
  frozen at 20. Writing after freeze throws under `WP_DEBUG`.
- Freezable registries for features and admin routes, tracking which plugin
  contributed each entry.
- `FeatureGate` — server-authoritative entitlement. Computes free-tier truth and
  makes no network calls, which is what keeps the free plugin compliant with the
  WordPress.org rule against calling home.
- Capabilities: `storecrew_manage`, `storecrew_view_analytics`,
  `storecrew_manage_agents`, `storecrew_converse`.
- Integration harness booting both plugins against a hook shim, with no database
  and no WordPress.

### Documentation

- `docs/01-prd.md` — 136 requirements with permanent IDs, competitive analysis,
  performance budgets, risk register.
- `docs/04-database-schema.md` — eleven tables, retention, privacy, the
  two-stage retrieval design answering R-TECH-01.
- `docs/15-free-premium-split.md` — the free/premium boundary and extension API
  contract.

### Security

- The free plugin makes no outbound calls of its own. Licence validation and
  update checks live entirely in the premium plugin.
