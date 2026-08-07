# Gate 4 Review — Findings

**Date:** 2026-08-07
**Scope:** 06 React Application Structure, 11 UI/UX Wireframes,
10 SaaS Subscription Architecture.
**Method:** three verification passes, one per document. 06 and 11 describe
built surfaces and were checked claim by claim against `admin-app/src`,
`widget-app/src`, `assets/`, `package.json`, and the controllers behind each
screen; bundle budgets were measured (`gzip -c`) rather than quoted. 10
designs ahead of code and cannot be verified the same way, so it was checked
for **internal consistency and for agreement with the identifiers and state
the code already commits to** — which is where it fails.

**Verdict: not approvable as written.** Nothing here is a security defect.
The security-adjacent claims in this set all hold and were spot-checked
directly: no `innerHTML` anywhere in either application, link schemes checked
on the *parsed* URL with `rel="noopener noreferrer nofollow"`, the nonce on
every admin request, entitlement re-checked server-side behind every route,
and no `wp_remote_*` call anywhere in the free plugin outside `Ai/Http` — so
10 § 1.2's "the free plugin never phones home" is true today.

The problems are the same two shapes the last two gates found, in a third
place. **Something built and shipped that nothing consumes** — this time the
feature catalog, computed on every `/bootstrap` and read by no component
(G4-C1) — and **documents describing screens that were subsequently built
differently**, concentrated in 11 § 3, where the Inbox wireframe gets four
of its five claims wrong. 10 adds a third shape the earlier gates did not
have: a specification whose identifiers match nothing in the code, in a way
that would fail silently and in the customer's disfavour (G4-S1).

---

## 1. Code defects and gaps

| ID | Finding | Evidence |
|---|---|---|
| **G4-C1** | **The feature catalog is shipped on every bootstrap and read by nothing.** `FeatureGate::manifest()` builds `catalog` — slug, label, tier and description for every registered feature — `BootstrapController` sends it in every `/bootstrap` response, and `types.ts` mirrors it faithfully. No component in `admin-app/src` references `catalog` at all. It is the fourth instance of Gate 2's G2-C1 pattern and the first where the unused thing is recomputed on every page load. | `src/Licensing/FeatureGate.php:130`, `src/Api/Rest/Controllers/BootstrapController.php:58`, `admin-app/src/lib/types.ts:5` |
| **G4-C2** | **The free plugin hardcodes premium agents, including their copy.** `CrewBar` ships a literal four-agent array naming `agent.marketing` and `agent.analytics` — slugs only Pro registers — with its own labels and roles ("Marketing", "Runs campaigns"). Pro's own `Feature` definitions say "Marketing agent" and "Segments customers, drafts campaigns, and creates coupons". Two sources of truth for the same string, and the free one wins on the screen. Directly contradicts 06 § 2.3: "The free plugin never knows what premium screens *are* — it renders from the manifest." The manifest that would fix this is the catalog G4-C1 found unused. | `admin-app/src/components/CrewBar.tsx:14-18`, `../storecrew-pro/src/Plugin.php:57-68` |
| **G4-C3** | **Approval arguments render as raw JSON, in two places.** 11 § 3.4 says arguments are "rendered as a definition list (**never raw JSON to a merchant**)". The Inbox renders `<pre>{JSON.stringify(item.arguments, null, 2)}</pre>`; the Overview renders `JSON.stringify(item.arguments)` inline. The values are redacted server-side (Gate 2's G2-C2 holds, so no raw email reaches the screen) — this is a presentation defect, not a leak, but it is the single most merchant-visible sentence in 11 § 3.4 and it is false. | `admin-app/src/pages/Inbox.tsx:36-41`, `admin-app/src/pages/Overview.tsx:60-62` |
| **G4-C4** | **The approval conflict is never explained to the merchant.** The server does exactly what 11 § 3.4 describes — deciding an already-decided call returns **409**, deliberately indistinguishable from "never existed". The Inbox mutation has no error branch and renders no error state, so the merchant clicks Approve on a stale card and *nothing visibly happens*: no message, no removal, no refetch. The doc's "the card explains it" describes a behaviour the client half was never given. | `src/Api/Rest/Controllers/ConversationController.php:222-227`, `admin-app/src/pages/Inbox.tsx:17-21` |
| **G4-C5** | **Escalated conversations never reach the Inbox.** 11 § 3.4: "Escalated conversations surface here as links into the inspector." The Inbox queries `/approvals` and renders nothing else. Escalation appears in exactly one place in the whole console — a red edge-bar on a Crew row — with no dedicated surface and no link built for it. The screen named "needs you" omits the half of "needs you" that is not a write approval. | `admin-app/src/pages/Inbox.tsx`, `admin-app/src/pages/Crew.tsx:30` |
| **G4-C6** | **The browser verification both documents rest on left no re-runnable artifact.** 06 § 4 is titled *Verification* and states both apps are "verified in real browsers", the widget with a specific count ("23 assertions"). The repository contains no Playwright config, spec, runner, or `package.json` script — `tests/` holds only `schema/` and `integration/`. The verification happened; nothing can repeat it. So the two bug classes 06 § 2.4 exists to warn about — cascade fights and cookie/cache behaviour, both invisible to the PHP suites — have **no regression guard at all**, and 14 § M1's exit criterion "both browser verifications pass" names an artifact that does not exist. Against this repo's own standard ("a rule that has never been observed to fire is not a rule"), this is the one finding here that will cost real time later. | `docs/06-react-app-structure.md:171-179`, `tests/`, `package.json` |

---

## 2. Specification defects in 10

10 is explicitly design-ahead-of-code, so "unbuilt" is not a finding. These
are places where the design contradicts identifiers or state the code has
already committed to — the parts an implementer would copy verbatim.

| ID | Finding |
|---|---|
| **G4-S1** | **Every entitlement key in § 2 and § 4 matches nothing in the code.** The document says `agents.marketing`, `agents.analytics`, `agents.custom`, `workflows`, `integrations.esp`, `whitelabel`. The registered slugs are `agent.marketing`, `agent.analytics`, `workflow.builder`, `integrations.email`, `agency.multisite`, `agency.whitelabel` — every one differs, mostly by a pluralisation; `agents.custom` is registered nowhere at all. This matters more than a typo because § 2 makes the keys load-bearing ("`FeatureGate` already maps feature slugs → tiers; the entitlement payload simply toggles which tier the gate evaluates as") and § 4's signed snapshot is keyed by them. **An unknown slug evaluates as not-entitled**, so a licence server built to this specification would issue a correctly-signed snapshot that grants a paying Pro customer exactly nothing — no error, no warning, and the failure lands on the customer. This is the pricing-honesty rule's failure mode wearing a different hat. |
| **G4-S2** | **§ 2's table mixes two kinds of key without saying so.** `conversations.monthly: 100` and `sites: 1` sit in the same table as the boolean feature slugs, but `FeatureGate` resolves slugs to booleans by tier and reads no numbers anywhere. A quota is a different mechanism that does not exist yet; presenting it in the same grid implies the gate already carries it. |
| **G4-S3** | **§ 8's "Conversation counting substrate ✅ Built" overstates what Gate 3 established.** § 5 is precise that a conversation consumes quota "when it first receives an agent answer" — a *conversation* metric. What exists is per-run counting; `UsageRepository::METRIC_CONVERSATION` is declared and written nowhere, which the Gate 3 approval moved into 14 § M4 for exactly this reason. The row should read ⬜ with the substrate named, or the free tier's cap will be enforced against a counter that is always zero. |

Noted but not a defect: § 4 says premium hands free "the snapshot" through an
extension-API filter. The built stub instead answers per-feature through
`storecrew_feature_enabled`, grant-only, with the reasoning that an expired
Pro licence must never switch off a free-tier agent — good reasoning, worth
naming in § 4 so the snapshot design does not read as replacing it.

---

## 3. Doc-stale edits

**11 § 3.1 (Overview)** — four claims, three wrong. The onboarding card is
drawn as a numbered three-step checklist ("1 ✓ Connect a provider …") and
described as replacing the stats "because nothing below it is real yet"; the
built card is a single sentence and a button, and the Today stats render
below it unconditionally. The crew cards are captioned "state label, **one
live number**" and carry no number — `CrewBar` renders label, role and state
only. "WORTH FIXING" is documented as stranded vectors and dead jobs; it
renders only when the encryption key is insecure, and stranded vectors are
surfaced elsewhere, on the Index model card. What is accurate: the four
Today cards, in that order, with those meanings.

**11 § 3.4 (Inbox)** — see G4-C3, G4-C4, G4-C5. Also: the buttons read
Approve and **Decline**; the doc says **[Approve] [Deny]**. Accurate: one
queue, reads never appear in it, and the empty state teaches.

**11 § 2 (Shell)** — "six destinations do not justify one [sidebar]". The nav
has **five**; the document's own diagram above the sentence shows five. Six
is the screen count, which is what 06 § 4 correctly says.

**11 § 1 (Vocabulary)** — the rule is stated as enforced ("enforced by copy
review, visible in every screen") and is roughly half-applied. Of the four
documented states — on duty · needs you · needs setup · off the floor — only
*On duty* and *Needs setup* are rendered as states. "Needs you" is a section
*title*, not a state. **"Off the floor" appears nowhere in the codebase**,
and `app.css`'s own header comment calls the same state "off shift" — the
vocabulary rule has a third variant inside the file that documents it.
`CrewBar` renders a fourth, undocumented state: "Not on your plan". What does
hold, and cleanly: every `Stat` in the app carries a unit (6 of 6), and
"unknown" is written, never rendered as 0.

**11 § 4** — "the locked-route treatment … the one place FR-DIST-10 permits
an invitation". Two problems. FR-DIST-10 permits upgrade prompts generally
and only forbids degrading free functionality to manufacture them, so 11
invents a stricter rule than the requirement it cites; and the built console
breaks the stricter rule anyway, since `CrewBar` shows two permanent
"Not on your plan" cards on the Overview. Either 11 adopts FR-DIST-10's
actual wording, or the crew board is a documented exception.

**06 § 1** — the admin budget reads "≤ 250 KB gz (at 94 KB)". Measured:
`app.js` is 94,220 B gzipped and `app.css` adds 3,639 B, so the shipped
bundle is 97.7 KB. Far inside budget either way; the figure should say
whether it counts CSS. The widget figure is exact — 5,361 B, against 45 KB.

**06 § 2.3** — "declared and entitled but not registered renders a 'plugin
not loaded' state". Substantively right; the built copy is "This screen has
not finished loading. Try refreshing." Quote the real string or drop the
quotation marks.

---

## 4. Decisions for the product owner

| ID | Decision |
|---|---|
| **G4-D1** | **The crew board (G4-C1 + G4-C2): drive it from the catalog, or declare the hardcoded board deliberate?** Rendering from `catalog` fixes both findings at once — it gives the unused payload a consumer and stops free owning premium's copy — but the catalog carries no per-agent "role" line and no ordering, so it needs two fields added before it can. Declaring the board deliberate is cheap and honest, and costs an edit to 06 § 2.3, whose "never knows what premium screens are" would no longer be true as written. |
| **G4-D2** | **Approval arguments (G4-C3): build the definition-list renderer, or amend 11?** The renderer is small but needs a per-tool label map to be better than JSON — `order_id` → "Order", and something sensible for arguments no shipped tool has yet. Amending 11 to "formatted JSON" is honest and takes a line, but the merchant reading `{"note":"…"}` before approving a write is the case FR-ADMIN-06 exists for. |
| **G4-D3** | **Browser tests (G4-C6): check in the Playwright suite, or downgrade the claim?** Checking it in makes it a real harness — it joins CLAUDE.md's test list and M1's exit criterion becomes runnable, at the cost of a Node test dependency and a browser in whatever runs it. Downgrading means 06 § 4 and 14 § M1 say "manually verified 2026-08-07" and the cascade-fight bug class stays guarded by nothing but care. |
| **G4-D4** | **Escalations (G4-C5): build the Inbox surface, or move the claim to Crew?** The data is there (`status = escalated`, already coloured on Crew) and the inspector link pattern already exists, so the surface is genuinely small. But "the merchant is notified of an escalation" is already an M1 ticket in its own right (email), and these should be decided together rather than producing two half-answers to "how does a merchant learn a customer needs a human". |

---

## What verified cleanly (the short list)

**06** — FR-ADMIN-01 holds absolutely: zero `@wordpress/*` references in
either app or in `package.json`, and the one sanctioned exception behaves as
described (`assets/blocks/chat-block.js`, no build, hand-written against
`wp.blocks` / `wp.blockEditor` / `wp.element` / `wp.i18n`). Two apps, two
Vite configs, two outputs, widget shipped as a single IIFE with its CSS
inlined and no separate stylesheet. Hash routing, TanStack Query for server
state, and a Zustand store that really does hold only theme plus the screen
registry. `types.ts` is an accurate mirror of the controllers — including
`catalog`, which is how G4-C1 was visible at all. `storecrewBoot` carries
exactly root, nonce, version and adminUrl; `X-WP-Nonce` on every request;
the envelope unwrapped once; `ApiError` typed and surfacing the server's own
wording. `registerScreen` exposed on `window.storecrew`, locked routes
rendering the upgrade panel, entitlement re-checked server-side. All eight
primitives exist and are the ones listed. The widget's five modules match
the documented structure, and § 3.2's security claims verify line by line —
no `innerHTML` in either application, scheme checked on the parsed URL,
`rel="noopener noreferrer nofollow"` set exactly as written.

**11** — the shift-board structure is real: status before numbers on every
screen, the edge-bar as the status carrier, `Stat` always carrying its unit,
"unknown" written rather than zeroed, no webfonts, one token set toggled by
class and defaulting to the OS preference. The Knowledge screen is accurate
in full, including the visible "results were cut short" label that keeps the
no-silent-caps rule true to the top of the stack. The inspector is accurate
in full, including each tool call's status and the "· changes data" tag on
writes.

**10** — the principles section is sound and, where it makes a claim about
today, true: the free plugin makes no remote call outside `Ai/Http`, and the
Pro stub degrades to free on any non-active status while announcing in its
own docblock that it is not a security boundary. The threat model is the
most honest section in the document set — licence checks as compliance
furniture, no obfuscation, no kill-switch — and § 8 correctly marks the stub
replacement ship-blocking. `FeatureGate` does map slugs to tiers today, and
routes do carry the `locked` flag the manifest promises (probed in
`verify-admin`).
