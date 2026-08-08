# 06 — React Application Structure

**Product:** StoreCrew AI
**Status:** Draft complete — documents the built applications as of 2026-08-07
**Version:** 0.1

There are **two** front-end applications, built separately on purpose because
their constraints are opposite:

| | Admin SPA (`admin-app/`) | Storefront widget (`widget-app/`) |
|---|---|---|
| Audience | Authenticated merchant | Anonymous shopper on a cached page |
| Stack | React 19 + TanStack Query + Zustand + Tailwind 4 | Framework-free TypeScript, hand-rolled CSS in a shadow root |
| Budget | ≤ 250 KB gz (at 98.0 KB: `app.js` 94,380 B + `app.css` 3,657 B) | ≤ 45 KB gz (at 5,361 B — one file, CSS inlined) |
| Build | `vite.config.ts` → `assets/admin/` | `vite.widget.config.ts` → `assets/widget/` (IIFE, CSS inlined) |

One build producing both would drag the widget toward the SPA's dependency
weight; the split is what keeps FR-CHAT-01's budget honest.

---

## 1. The Rule Above All Others (FR-ADMIN-01)

**No `@wordpress/*` package, anywhere, in either app.** Not the component
library, not the data layer, not the element wrapper. Two reasons, one
technical and one product:

- Core ships whichever React its release pins; a plugin borrowing it inherits
  every future core upgrade as an untested breaking change. React is bundled.
- The admin should feel like a SaaS product that happens to live inside
  WordPress; inheriting WordPress's component styling guarantees the
  opposite.

The single sanctioned exception lives outside both apps:
`assets/blocks/chat-block.js` is hand-written against the editor's own
`wp.*` globals (no build, no dependency) because it runs only inside
Gutenberg, which has already loaded them.

---

## 2. Admin SPA

### 2.1 Shell

```
main.tsx        Mounts into #storecrew-root (the AdminPage mount point)
App.tsx         HashRouter; bootstrap + approvals queries; route table
components/     Layout (nav, theme toggle, approval badge), CrewBar,
                primitives (Card, Section, Label, Button, Stat, Empty,
                Spinner, Problem)
lib/            api.ts (fetch + nonce + envelope unwrap, typed ApiError)
                store.ts (theme; add-on screen registry)
                types.ts (hand-written mirror of the REST payloads)
pages/          Overview, Crew, Knowledge, Inbox, ConversationDetail, Settings,
                Setup (the FR-ADMIN-02 flow — 11 § 3.7)
styles/app.css  Tokens, wp-admin armour (§ 2.4)
```

- **Routing is hash-based.** WordPress owns `admin.php?page=storecrew`; a
  history router would need a rewrite rule to survive refresh. Hash deep
  links work with zero server involvement.
- **Server state lives in TanStack Query, UI state in Zustand** — and there
  is almost no UI state: theme, and the add-on screen registry. Anything the
  server knows is fetched, cached, and invalidated by mutation, never
  mirrored into a store.
- **Types are a hand-written mirror** of the controllers, not generated: the
  API is 24 routes; a generator is a build dependency heavier than the file
  it would produce. The mirror lives in one file so drift is one diff.

### 2.2 Bootstrap contract

`AdminPage` prints one localized object (`storecrewBoot`: REST root, nonce,
version, adminUrl, siteUrl, userName) and one mount div. Everything else —
features, routes, onboarding state — comes from `GET /bootstrap`. `siteUrl` is
`home_url()` rather than something derived from `adminUrl`, which is wrong on
every subdirectory install; the setup flow's last step sends the merchant to
look at their own storefront. The nonce is attached to
every request; without it a logged-in cookie is *not* REST authentication,
which is what stops a third-party page driving the API with the merchant's
session.

### 2.3 Premium composition (FR-DIST-12)

The shell renders add-on screens from two sources that must agree: the
server's bootstrap manifest declares a route (and whether it is entitled),
and the add-on's bundle registers the implementation (`registerScreen()`). A
route declared but not entitled renders the upgrade panel ("This is part of
a paid plan."); declared and entitled but not registered renders "This
screen has not finished loading. Try refreshing." Entitlement remains
server-authoritative; the manifest is a rendering hint and every controller
re-checks (FR-DIST-09).

**The registration contract is a DOM mount, not a React component**
(built 2026-08-08, first consumed by Pro's licence UI):
`window.storecrew.registerScreen(path, { mount(el) → cleanup? })`. This
follows from the shell bundling its own React — an add-on's bundle has no
React instance to build elements with, and a component contract would force
every add-on to ship a second React, the exact thing FR-DIST-12 forbids.
The shell wraps the mount in an internal component that owns the element's
lifecycle; the page's design tokens (`--line`, `--field`, `--text-dim`, …)
are custom properties, so a plain-DOM screen follows the light/dark toggle
for free. The registry is reactive: add-on bundles are enqueued *after* the
shell (via the `storecrew_admin_assets` action, which passes the shell's
script handle for dependency declarations and fires only after
`window.storecrew` exists), so a screen registered after first render
appears without a refresh.

**Settings tabs are the second registry surface** (2026-08-08):
`window.storecrew.registerSettingsTab(id, { label, mount(el) → cleanup? })`
contributes a pane to the Settings screen's tab bar instead of a whole
route. Same mount contract, plus the label — which arrives already
translated, because the add-on localises its own strings server-side and
the shell has no catalog for words it has never heard of. This is where an
add-on's *configuration* belongs: a sidebar route is for a place the
merchant works, not a form they visit twice a year. Pro's licence pane
moved here from a dedicated `/licence` route the same day it was built —
the route registration is gone, not merely unlinked. A contributed tab
joins the URL contract too (`?tab=<id>`), so a deep link pasted into a
support thread lands on the pane.

**The shell renders from the manifest** (G4-D1, ratified and built
2026-08-07). `Layout` appends every contributed route with `inMenu`, in
declared `order`, after its own five screens — locked entries carry a "pro"
mark and lead to the upgrade panel, which is what makes a locked route an
invitation rather than a dead link (FR-DIST-10, FR-DIST-12). `CrewBar`
renders every `agent.*` feature from the manifest's `catalog` in registry
order, with the registering plugin's own label and description — the free
plugin owns none of premium's copy. Two deliberate limits, so the next
reviewer does not re-find them: `AdminRoute.icon` is unconsumed because the
nav is text-only by the design language's own rule, and the catalog gained
no new fields — `description` is the role line and registry order the
ordering, until a contributed agent actually needs placement control.

`CrewBar` reads the merchant's own roster (`GET /agents`) alongside the
manifest, because entitlement and *standing* are different facts: an agent
stood down in the setup flow must not keep reading "on duty". An agent absent
from that roster is treated as on — the same default the orchestrator takes
for an agent with no configuration row, so a still-loading roster never
flickers the whole crew to "stood down".

### 2.4 Surviving wp-admin (the hard-won part)

Recorded here because it will bite again:

- **Cascade layers lose to wp-admin.** Layer order is consulted *before*
  specificity, and admin CSS is unlayered — so `@import 'tailwindcss'`
  (which layers utilities) loses to `body { color }` at any specificity.
  `app.css` emits utilities **unlayered and important**.
- **Preflight is not imported** — it would reset the admin menu and toolbar.
  The app's reset is scoped to `#storecrew-root` at ID specificity, which is
  what outranks `input[type='text']:focus` in forms.css.
- **The failure mode is invisible in light mode** (WordPress grey-on-white
  resembles intent). Every theme change is verified in dark mode, where
  surfaces flip and unstyled text does not follow.
- The host page strips admin notices on our screen only, and `AdminPage`
  registers unconditionally — an `is_admin()` guard would gate a gate and
  hide the menu from WP-CLI, the only harness that can test it.

### 2.5 Design language

The console is a **shift board, not an analytics dashboard**: status first
("on duty / needs you / needs setup"), numbers second, no webfonts (a CDN
font leaks every admin page view and breaks offline). Wireframes and
vocabulary in [11](11-wireframes.md).

---

## 3. Storefront Widget

### 3.1 Structure

```
main.ts     Boot: read window.storecrewChat (REST root + auto flag only),
            fetch /chat/boot, mount shadow roots, accent contrast math
widget.ts   ChatWidget: launcher, panel, log, composer; focus trap; a11y
api.ts      Fetch client: cookie-first session, header fallback,
            sessionStorage token cache, typed ApiFailure
render.ts   Markdown-subset → DOM via createTextNode/createElement only
types.ts    Mirror of the four chat payloads
widget.css  All styles; injected into the shadow root, themed by :host vars
```

### 3.2 Structural decisions

- **Shadow DOM, both directions.** Storefront themes write
  `div { margin: 0 !important }`; out-specifying every theme on .org is a
  fight with no end, and the boundary also keeps our styles off the
  merchant's page. `:host` re-states inherited properties explicitly rather
  than `all: initial`, which would flatten the host box and break inline
  placement.
- **Cache-safe by construction**: the page carries no nonce and no state
  (03 § 8); everything per-visitor arrives from `/chat/boot`. The session
  token lives in an HttpOnly cookie with a header fallback for hosts whose
  page cache strips `Set-Cookie`; the fallback copy sits in
  `sessionStorage` (dies with the tab) and its XSS exposure is bounded by
  the token authorising exactly one visitor's own thread.
- **Conversations open on the first message, not on page load** — a
  thousand daily visitors must not become a thousand empty inbox rows.
- **`renderMessage` assigns no `innerHTML`, ever.** Model output becomes
  text nodes inside created elements; bare URLs become links only after the
  *parsed* scheme is checked (http/https), with
  `rel="noopener noreferrer nofollow"`. Lines are grouped into runs so the
  commonest model shape — a sentence followed by dashed bullets — renders as
  a list rather than literal hyphens (found live).
- **Accessibility is structural** (FR-CHAT-04): the floating panel is a
  labelled non-modal dialog with a focus trap and Escape-to-launcher; the
  log is a polite live region; every control clears 44 px; motion honours
  `prefers-reduced-motion`; the accent's ink colour is computed from
  luminance so a merchant's pale brand colour cannot produce white-on-yellow.
- **Failure renders as absence or a sentence** (FR-CHAT-03): no config → no
  widget; boot failure → no widget; API failure mid-conversation → the
  offline notice; the raw error code never reaches the shopper.

### 3.3 Placement

Floating launcher by default (`autoPlace`), standing down automatically on
pages where the merchant placed the panel via `[storecrew_chat]` or the
`storecrew/chat` block (FR-CHAT-07) — two panels on one page would share a
conversation but not a transcript.

---

## 4. Verification

Both apps were verified in real browsers on **2026-08-07**, not only by unit
logic: the SPA via Playwright across all seven screens, both themes, mobile,
and a settings write round-trip; the widget via Playwright (23 assertions:
keyboard path, focus, a11y semantics, Markdown, reload persistence,
dark/mobile) and live against a real provider (five-turn conversation). The
browser is the only harness that catches cascade fights and cache/cookie
behaviour — the two bug classes this document exists to warn about.

**The suite is checked in** (G4-D3, ratified 2026-08-07): `tests/browser/`
holds widget and admin specs as plain-node Playwright scripts under the same
PASS/FAIL discipline as `tests/schema/`, run by `npm run test:browser`. The
admin spec walks every screen in **dark mode**, sampling computed text
colour against wp-admin's default — the cascade-defeat signature — and fails
on any console error; the widget spec covers the keyboard path, dialog
semantics, mobile, and the cache-safety rules, with the one token-spending
section opt-in (`STORECREW_TEST_LIVE=1`) and admin credentials via
environment so an unconfigured run skips loudly instead of failing
mysteriously. The two bug classes named above now have a harness that can
observe them fire.
