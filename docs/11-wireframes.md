# 11 — UI/UX Wireframes

**Product:** StoreCrew AI
**Status:** Draft complete — documents the built screens as of 2026-08-07
**Version:** 0.1

These are as-built wireframes: every screen below exists, renders in both
themes, and has been walked in a real browser. The document's job is to
record the *intent* behind each layout so future screens (Phase 2's
Marketing/Analytics panels, the workflow builder) extend a language rather
than inventing one.

---

## 1. The Design Language: a Shift Board

The organising metaphor is a **staff shift board, not an analytics
dashboard**. The merchant hired a crew; the console answers a manager's
questions in a manager's order:

1. **Who is on, and is anything stuck?** — status before numbers, always.
2. **What needs me?** — approvals and escalations are the only red things.
3. **How is it going?** — the numbers, secondary by design.

Vocabulary rules:

- States are shift language, never "enabled/disabled/error". Three describe
  an agent or a surface — **on duty · needs setup · off the floor** — and one
  describes the merchant's own queue, **needs you**, which is a section title
  rather than anything's state. A fourth agent state exists and is not shift
  language: **"not on your plan"**, rendered by `CrewBar` for an unentitled
  agent. It is deliberate — a locked agent is not *off shift*, it was never
  hired — but it is an exception to the rule above, so it is written here
  rather than left to be discovered.
- The vocabulary is enforced by copy review only, and copy review has already
  missed once: `app.css`'s header comment calls the storefront's off state
  "off shift" while the screen it documents renders "Off the floor". A rule
  guarded by attention alone drifts inside the file that states it.
- Numbers carry their meaning next to them (`Stat` = value + unit label);
  a bare number is a defect.
- Errors say the next action, not the failure class.
- **Unknown is written "unknown", never rendered as 0** — the UI mirror of
  the pricing rule (09 § 5).

Visual system: neutral surfaces with one signal colour and one alert colour;
micro-labels (mono, uppercase, wide tracking) as the connective tissue;
cards with a status edge-bar; no webfonts (privacy + offline); light/dark
from one token set, toggled by class, defaulting to the OS preference.

---

## 2. Shell

```
┌────────────────────────────────────────────────────────────┐
│ StoreCrew   Overview Crew Knowledge Inbox(2) Settings  ◐  │  ← Inbox badge =
├────────────────────────────────────────────────────────────┤    pending count
│                                                            │
│                    <active screen>                         │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

Full-viewport (admin notices removed on this screen only, padding stripped);
top navigation, not a sidebar — five destinations do not justify one, and the
board metaphor wants width. (Five in the nav; six screens, because the
inspector is reached from a row, not from the bar.)

**The nav is hardcoded and does not read the route manifest.** `Layout`
ships a literal five-item array, so the `icon`, `order` and `inMenu` fields a
contributed route carries reach the browser and are used by nothing — a
premium screen renders at its hash and has no link pointing at it. Recorded
as G4-C7; FR-DIST-12 does not hold until the bar is built from
`bootstrap.routes`. The theme toggle shows its state as a label
("Light"/"Dark"), not an icon riddle. Responsive to 768 px (FR-ADMIN-07):
nav collapses to wrap, stat rows stack.

---

## 3. Screens

### 3.1 Overview — "the board" (FR-ADMIN-03/08)

```
THE CREW
┌─ Sales ─────────────┐ ┌─ Support ───────────┐
│ Finds products    ● │ │ Handles orders    ● │   CrewBar: one card per
│ ON DUTY             │ │ ON DUTY             │   agent — role line, state
└─────────────────────┘ └─────────────────────┘   label, live dot. No number.

[ Before the crew can start ]          ← onboarding card, only while
  No AI provider is connected yet.       incomplete (FR-ADMIN-02).
  Connect a provider and the crew        One sentence naming the single
  can start answering.                   thing blocking the crew, and one
  [ Open settings ]                      button. The stats still render.

NEEDS YOU (2)                [ Open inbox ]   ← the three oldest pending
┌ order.note   {"note":"…"} ┐                   approvals; the section stands
└───────────────────────────┘                   down to a teaching sentence
                                                when nothing is waiting.
TODAY
┌ Knowledge ┐ ┌ Spend this month ┐ ┌ Background work ┐ ┌ Index model ┐
│ 67 ready  │ │ $0.42 / $5 cap   │ │ ● queue healthy │ │ gemini-…    │
└───────────┘ └──────────────────┘ └─────────────────┘ └─────────────┘

WORTH FIXING                            ← renders only when the encryption
  ▸ define STORECREW_KEY in wp-config      key is insecure
```

Job health and index health live here, on the screen operators open —
FR-ADMIN-08 exists because burying them in a tools page is how a dead queue
goes unnoticed for a month.

Three notes where the built screen decided something this wireframe's first
draft had not:

- **The onboarding card does not replace the stats.** The first draft had it
  standing in for them "because nothing below it is real yet". A half-set-up
  store's Today numbers are real and are exactly what tells the merchant
  whether the step they just finished took, so both render.
- **The crew cards carry no number.** Status before numbers is the § 1 rule,
  and an agent's live number ("handled 12 today") turned out to be a Today
  stat wearing a costume. The cards carry role, state, and a live dot.
- **Stranded vectors are surfaced on the Index model card**, not in "Worth
  fixing" — next to the model that stranded them, where the count means
  something. "Worth fixing" is reserved for the insecure-key advice.

### 3.2 Crew

"Who is on" (the same CrewBar) + recent conversations with status, channel,
and last activity. Each row links into the inspector. Per-agent
configuration (persona editing, per-tool autonomy — FR-ADMIN-05's full
depth) is Phase 2 UI; the framework beneath it already exists.

### 3.3 Knowledge

```
┌ Ready to search ┐ ┌ Products ┐ ┌ Pages ┐
│    67 / 67      │ │    30    │ │  33   │
└─────────────────┘ └──────────┘ └───────┘
[ Reindex ]  [ Embed pending ]

TRY A QUESTION
┌ ask what a shopper would ask …            [Search] ┐
│ #12  Beanie — winter warmth …        score 0.83    │
│ dense scan · 67 considered · (results cut short?)  │
└────────────────────────────────────────────────────┘
```

The search box is FR-KB-10 made tactile: the merchant sees exactly what the
agent would retrieve, with the strategy and candidate count named — and a
visible "results were cut short" label when truncation fired, because silent
caps are forbidden all the way up the stack.

### 3.4 Inbox — "needs you" (FR-ADMIN-06)

One queue: pending write actions, each card showing the tool, its arguments,
when it was asked, and **[Approve] [Decline]**. Reads never appear here —
filling the queue with lookups is how a merchant is trained to approve
without reading. Deciding an already-decided call returns a **409**,
deliberately indistinguishable from a call that never existed, and the card
says so rather than going quiet. Empty state says what *would* arrive here —
an empty queue must teach, not confuse.

Two intentions in this section are **designed, not built**, and are open
decisions from the Gate 4 review rather than descriptions of the screen:

- **Arguments as a definition list, never raw JSON to a merchant** (G4-D2).
  Today both this screen and the Overview print `JSON.stringify`. The values
  are redacted server-side, so this is legibility rather than exposure — but
  FR-ADMIN-06 exists for the merchant who reads a write before allowing it,
  and `{"note":"…"}` is not reading.
- **Escalated conversations surfacing here as links into the inspector**
  (G4-D4). Today escalation appears once in the whole console, as a red edge
  on a Crew row. A screen titled "needs you" that omits the half of "needs
  you" that is not a write approval is the gap; whether it closes here or on
  Crew is decided together with the escalation email (14 § M1).

### 3.5 Conversation detail — the inspector (FR-ADMIN-04)

```
← Back    open · widget · identified on order 421

TRANSCRIPT                     WHAT HAPPENED UNDERNEATH
┌ Customer  10:41 ┐            ┌ Run · support · gemini-3.6-flash ┐
│ Where is my …   │            │ completed · 3.7k in / 142 out    │
├ support   10:41 ┤            │ $unknown · 10.4 s                │
│ Your order #421…│            │ Read 2 passages (ids + scores)   │
└─────────────────┘            │ ▸ identity.verify  succeeded     │
                               │ ▸ order.lookup     succeeded     │
                               └──────────────────────────────────┘
```

Two columns: what the customer saw, and why. Every run shows model, tokens,
cost-or-unknown, latency, retrieval trace, and each tool call with its
authorisation mode and status — a denied call renders as visibly as a
successful one, because "the agent tried and was refused" is exactly what a
merchant auditing an incident needs to see. Writes are tagged "changes
data".

### 3.6 Settings

Four sections, in trust order: **Connections** (provider cards: capability
labels in plain language — "can read your store" not "embeddings" — masked
key hint, paste-and-verify, test button); **Which model does what** (one row
per task with a plain-language title and the honest hint that routing
deserves a cheap model); the pricing-freshness line ("rates last checked
{date}; unknown counted as unknown, not free"); **On the storefront** (the
on-duty switch — disabled with an explanation until a chat model resolves —
launcher/title/greeting copy, accent with computed-contrast preview, corner,
float-vs-shortcode placement).

### 3.7 Storefront widget (FR-CHAT-04)

Wireframe and behaviour in [06 § 3](06-react-app-structure.md); design
rules: merchant accent with luminance-computed ink, system font stack,
bubbles under 85% width, typing indicator that respects reduced motion,
full-screen sheet under 480 px, and the offline notice as the only failure
surface a shopper ever sees.

---

## 4. Patterns for Contributed Screens

Premium panels (FR-DIST-12) inherit: the primitives (`Card`, `Section`,
`Stat`, `Label`, `Empty`, `Problem`), the vocabulary rules (§ 1), the
"status first, numbers second" order, and the locked-route treatment — a
route the manifest declares but does not entitle renders the upgrade panel
rather than disappearing, so the merchant can see what the plan would add.

FR-DIST-10 is the governing rule and it is permissive: upgrade prompts are
allowed, and what is forbidden is **degrading free functionality to
manufacture one**. Earlier drafts of this section stated the locked route
was "the one place" an invitation may appear, which invented a stricter rule
than the requirement it cited — and the built console does not follow the
stricter version anyway, since `CrewBar` shows two permanent "Not on your
plan" cards on the Overview. The console's own convention, weaker than a
rule and worth keeping: an invitation sits where the capability would have
been, never in the path of something that works.

Neither treatment reaches a merchant today — nothing links to a contributed
route (§ 2, G4-C7). A contributed screen that needs a new primitive
contributes the *need* upstream rather than forking the look.

---

## 5. Accessibility Commitments (FR-CHAT-04, PRD § 8.5)

WCAG 2.2 AA on both surfaces: full keyboard operation (widget: verified
Enter-open → focus-in-composer → Escape-returns-to-launcher), visible focus
everywhere (restyled, never removed), live-region announcement of streamed
content when streaming lands, 44 px targets, reduced-motion honoured, and
contrast maintained by construction (computed accent ink; token pairs
checked in both themes — in *dark mode first*, which is where wp-admin
cascade failures hide).
