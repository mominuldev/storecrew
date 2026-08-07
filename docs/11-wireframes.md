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

Vocabulary rules (enforced by copy review, visible in every screen):

- States are shift language: **on duty · needs you · needs setup · off the
  floor** — never "enabled/disabled/error".
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
top navigation, not a sidebar — six destinations do not justify one, and the
board metaphor wants width. The theme toggle shows its state as a label
("Light"/"Dark"), not an icon riddle. Responsive to 768 px (FR-ADMIN-07):
nav collapses to wrap, stat rows stack.

---

## 3. Screens

### 3.1 Overview — "the board" (FR-ADMIN-03/08)

```
THE CREW
┌─ Sales ─────────────┐ ┌─ Support ───────────┐
│ ● on duty           │ │ ● on duty           │   CrewBar: one card per
│ handled 12 today    │ │ 2 waiting for you   │   agent, state label,
└─────────────────────┘ └─────────────────────┘   one live number

[ Before the crew can start ]          ← onboarding card, only while
  1 ✓ Connect a provider                 incomplete (FR-ADMIN-02);
  2 ✓ Index your store                    replaces the stats, because
  3 ○ Put chat on the storefront          nothing below it is real yet

TODAY
┌ Knowledge ┐ ┌ Spend this month ┐ ┌ Background work ┐ ┌ Index model ┐
│ 67 ready  │ │ $0.42 / $5 cap   │ │ ● queue healthy │ │ gemini-…    │
└───────────┘ └──────────────────┘ └─────────────────┘ └─────────────┘

WORTH FIXING                            ← only renders when non-empty:
  ▸ 60 passages embedded by an old model  stranded vectors, dead jobs,
```

Job health and index health live here, on the screen operators open —
FR-ADMIN-08 exists because burying them in a tools page is how a dead queue
goes unnoticed for a month.

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

One queue: pending write actions, each card showing the tool, its arguments
rendered as a definition list (never raw JSON to a merchant), when it was
asked, and **[Approve] [Deny]**. Deciding an already-decided call returns a
conflict and the card explains it. Escalated conversations surface here as
links into the inspector. Empty state says what *would* arrive here — an
empty queue must teach, not confuse.

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
(the one place FR-DIST-10 permits an invitation, and it may never replace a
working free feature). A contributed screen that needs a new primitive
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
