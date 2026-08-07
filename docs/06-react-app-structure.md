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
pages/          Overview, Crew, Knowledge, Inbox, ConversationDetail, Settings
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
  API is 21 routes; a generator is a build dependency heavier than the file
  it would produce. The mirror lives in one file so drift is one diff.

### 2.2 Bootstrap contract

`AdminPage` prints one localized object (`storecrewBoot`: REST root, nonce,
version, adminUrl) and one mount div. Everything else — features, routes,
onboarding state — comes from `GET /bootstrap`. The nonce is attached to
every request; without it a logged-in cookie is *not* REST authentication,
which is what stops a third-party page driving the API with the merchant's
session.

### 2.3 Premium composition (FR-DIST-12)

The shell renders add-on screens from two sources that must agree: the
server's bootstrap manifest declares a route (and whether it is entitled),
and the premium bundle registers the component (`registerScreen()`). A route
declared but not entitled renders the upgrade panel ("This is part of a paid
plan."); declared and entitled but not registered renders "This screen has
not finished loading. Try refreshing." Entitlement remains
server-authoritative; the manifest is a rendering hint and every controller
re-checks (FR-DIST-09).

**Two qualifications, both open findings from the Gate 4 review.** The free
plugin does not yet render purely from the manifest, and in one place it
does the opposite:

- **The navigation ignores the manifest entirely** (G4-C7). `Layout` holds a
  hardcoded five-item array, so a contributed route's `label`, `icon`,
  `order` and `inMenu` are serialised, typed, delivered — and read by
  nothing. The route resolves at its hash and no link points to it, which
  makes both treatments above unreachable in practice.
- **`CrewBar` hardcodes premium's agents and their copy** (G4-C2), including
  `agent.marketing` and `agent.analytics` with labels and role lines that
  already disagree with Pro's own `Feature` definitions. The manifest's
  `catalog` — slug, label, tier and description for every registered feature
  — is computed on every bootstrap and consumed by no component (G4-C1),
  which is exactly the payload that would fix it.

So 15 § 5's intent holds in the server's design and not yet in the client.
Until G4-D1 is decided, "the free plugin never knows what premium screens
are" describes where this is going, not where it is.

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
logic: the SPA via Playwright across all six screens, both themes, mobile,
and a settings write round-trip; the widget via Playwright (23 assertions:
keyboard path, focus, a11y semantics, Markdown, reload persistence,
dark/mobile) and live against a real provider (five-turn conversation). The
browser is the only harness that catches cascade fights and cache/cookie
behaviour — the two bug classes this document exists to warn about.

**None of it is checked in** (G4-C6). The repository holds no Playwright
config, spec, or runner; `tests/` contains `schema/` and `integration/` and
nothing else. Those runs happened and cannot be repeated, so by this
project's own standard — a rule that has never been observed to fire is not
a rule — the two bug classes named above are guarded today by care alone,
and 14 § M1's "both browser verifications pass" names an artifact that does
not exist. G4-D3 decides whether the suite is checked in or the claim is
downgraded to a dated manual verification.
