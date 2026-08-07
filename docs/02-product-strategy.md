# 02 — Product Strategy

**Product:** StoreCrew AI — The AI Employee Platform for WooCommerce
**Owner:** Decent Themes
**Status:** Gate 1 approved — D1–D10 ratified as recommended
**Version:** 1.0
**Date:** 2026-08-07 (ratified 2026-08-07)

This document turns the PRD's positioning into decisions: who pays, what they
pay for, how they find the product, and in what order the market is attacked.
Where the PRD ([01-prd.md](01-prd.md)) records *what* the product must do, this
records *why that set and not another*, and what we deliberately concede.

Positions taken here on the PRD's § 13 open questions are **recommendations
with reasoning**, marked ⚖️, for ratification at the Gate 1 review. They are
written so that a reviewer can reject one without unravelling the rest.

---

## 1. The One-Sentence Strategy

> Win the WordPress.org install base with a genuinely useful free support-and-
> sales employee, then convert the merchants whose stores grow into the
> operational pain that Marketing, Analytics, and automation solve — charging
> for **outcomes delivered by software the merchant already trusts**, never for
> model tokens.

Everything below is a consequence of that sentence.

---

## 2. Market

### 2.1 Sizing, honestly

Public figures put WooCommerce at roughly 3–4 million live stores, the largest
share of e-commerce sites on the web. Most are dormant, tiny, or hobbyist. The
addressable segment for StoreCrew is the slice with real order volume and no
staff to absorb it — the PRD's Growing Store Owner ($10k–250k/mo GMV).

We size by analogy rather than TAM arithmetic: mature commercial Woo plugins
in an adjacent price band (order automation, memberships, bookings) sustain
10,000–50,000 paying customers. The PRD's 12-month business target — 5,000
active installs, 400 paying — is under 1% of that comparable ceiling. The
strategy does not require winning the market; it requires not being ignored by
it.

### 2.2 Why this market is winnable by a plugin, not a SaaS

The Shopify-first SaaS competitors (PRD § 3.1) cannot follow us into our
position without abandoning their architecture:

- **Their unit economics require markup on usage.** A hosted SaaS pays for
  inference and resells it per conversation. StoreCrew's BYO-key model bills
  nothing per conversation, which means our free tier costs us nothing to
  operate and our paid tiers are pure software margin. A SaaS cannot match a
  $0 marginal-cost free tier without losing money on every install.
- **Their integration posture cannot reach live store state.** A nightly-sync
  REST integration cannot read a variation's stock at answer time (FR-KB-08's
  live-read design) or write an order note inside the merchant's own audit
  domain. In-process is not an implementation detail; it is the moat.
- **Their data posture is an objection we never face.** EU merchants and
  agencies with data-processing agreements care that conversations, orders,
  and customer emails never egress. "Your data stays on your server" is a
  compliance answer, not a slogan.

The WordPress-native competitor set (PRD § 3.2) *could* follow, and the defence
against them is speed and depth: multi-agent orchestration with write actions
is quarters of work beyond a chat widget, and the extension API (Deliverable
15) turns our head start into an ecosystem position — third parties build on
StoreCrew rather than beside it (FR-DIST-07).

### 2.3 Personas, full treatment

The PRD § 5 table, expanded with the decision-relevant detail: what triggers
the search, what converts them, and what churns them.

**Solo Merchant** — free tier's resident, Pro's seed.
- *Trigger:* the evening they answer the same "where is my order?" email for
  the fifth time. Search phrasing: "WooCommerce AI chatbot", "WooCommerce
  support automation".
- *Converts on:* nothing, initially — and that is fine. They are the install
  base, the reviews, the word of mouth. A minority grow into the next persona.
- *Churns on:* setup friction. If the path from install to first answered
  question exceeds one sitting, they are gone (PRD success metric: ≤ 15 min
  median time-to-value). The BYO-key step is the cliff — see § 5.3.

**Growing Store Owner** — the revenue persona; every Pro decision optimises
for them.
- *Trigger:* support volume outgrows the owner's inbox, or a hired VA needs
  tooling. Often arrives via an agency's recommendation.
- *Converts on:* the moment free demonstrably resolves support conversations
  and they want the same competence pointed at revenue — campaigns, abandoned
  carts, analytics they will actually read. Conversion is **capability-led,
  not quota-led**: they upgrade for Marketing and Analytics agents, with the
  conversation cap as a secondary nudge, not the primary lever (R-MKT-01
  hedges the cap being wrong).
- *Churns on:* an unexplained AI answer that embarrassed them in front of a
  customer. This is why the conversation inspector (FR-ADMIN-04) and
  approval-gated writes (FR-AGENT-05) are retention features, not admin
  chrome.

**WooCommerce Agency** — the multiplier persona.
- *Trigger:* a client asks "can we have AI support like the big stores?" and
  the agency needs an answer that fits a WordPress maintenance retainer.
- *Converts on:* multi-site licensing and white-label (FR-LIC-04/05). One
  agency deal equals 10–100 merchant installs, and agencies do the onboarding
  work — including the API-key step — that solo merchants stumble on.
- *Churns on:* anything that makes them look bad in a client demo, and
  release instability. Agencies are the constituency for the probe-tested
  guard discipline this codebase already practices.

**Store Operations Manager** — acknowledged, deferred. They need roles,
permissions depth, and procurement paperwork. Phase 3 at the earliest; we do
not let their requirements complicate Phase 1.

---

## 3. Packaging

### 3.1 The tier line

Deliverable 15 § 2.3 already draws it: **free answers questions and resolves
support; premium runs campaigns, builds automations, and reports on the
business.** Strategically restated: **free saves the merchant time; paid makes
the merchant money.** Time saved is how trust is earned; money made is what a
subscription price is justified against.

| | Free | Pro | Agency |
|---|---|---|---|
| Agents | Sales + Support | + Marketing, Analytics, custom agent builder | Pro, across sites |
| Actions | Read tools + approval-gated order notes | + Coupons, campaigns, segments, workflow automation | Pro |
| Conversations | 100/month metered | Uncapped (fair-use) | Uncapped |
| Sites | 1 | 1 | Multi-site, white-label |
| Integrations | — | ESP adapters (FR-MKT-04) | Pro |
| Support | Community | Priority | Priority + partner channel |

### 3.2 ⚖️ Open question 1 (PRD § 13.1): what does the free tier meter?

**Recommendation: meter conversations, at 100/month, exactly as FR-LIC-02
states — and treat the number as configuration to be tuned, not a promise.**

Reasoning against the alternatives:

- *Indexed documents* punishes catalogue size, which correlates with merchant
  sophistication but not with value received — and it would push the
  largest-catalogue merchants (the best Pro prospects) away at install time,
  before the product has shown value.
- *Agent count* is already the tier line (Sales/Support free, the rest paid);
  metering it twice adds no pressure.
- *Conversations* meter the value actually delivered — a resolved conversation
  is the product working — and the schema already counts them
  (`wp_scr_usage_events`; the metering lives in free per 15 § 9.2, so premium
  lifts the cap through entitlements rather than by shipping the meter).

100/month ≈ 3–4 conversations/day: genuinely useful to a solo merchant,
visibly insufficient for a store doing 500 orders/month. R-MKT-01 (cap too
generous) is hedged by instrumentation from day one and by the cap living in
configuration.

### 3.3 ⚖️ Open question (15 § 9.1): is the Sales agent free?

**Recommendation: yes — keep Sales free, exactly as built.** Sales is the
demo. A free tier that only deflects support saves time invisibly; a free tier
that also finds products shows the merchant an AI *selling* — which is the
experience that makes the Marketing agent's pitch ("now let it chase the carts
it couldn't close") legible. The conversion driver is not withholding Sales;
it is showing Sales and selling its amplification. Moving Sales behind Pro
would also move code between plugins now that both agents ship in free —
a cost the strategy would need to justify, and cannot.

### 3.4 ⚖️ Pricing posture (figures for Gate 1 ratification)

Anchors: StoreClerk bills $49.99–199.99/mo *plus* per-conversation economics;
commercial Woo plugins in the operations band cluster at $99–299/year;
merchants additionally pay their own model bill, which for a 1,000-
conversation month on current flash-tier pricing is small single-digit
dollars.

**Recommendation: annual pricing in the WordPress habit, not SaaS-monthly.**

| Tier | Recommended price | Rationale |
|---|---|---|
| Pro | **$199/year** intro (list $249) | Under the psychological $20/mo line; 2–4× the operations-plugin norm is defensible because the product acts rather than reports. Materially cheaper than one month of the SaaS competitors' mid tier. |
| Agency | **$599/year**, 25 sites, white-label | Priced per-agency not per-site so the agency's margin improves as they deploy it; 25 covers the median agency's active-retainer book. |

Not recommended: usage-based pricing of any kind. The merchant already has a
usage bill (their provider key). Two meters is one too many, and "no markup on
model usage" (PRD § 4) is the positioning — pricing must not contradict it.

### 3.5 ⚖️ Open questions resolved by packaging

- **15 § 9.3 — one premium plugin or two:** one plugin, tiered entitlements.
  The Agency tier differs by *license shape* (sites, branding), not by code
  surface large enough to justify tripling the release matrix. Revisit only if
  Agency-only code (team roles, client reporting) grows past roughly a third
  of the premium tree.
- **15 § 9.4 — third-party workflow nodes in v1:** no. The extension API's
  public surface is a compatibility promise (FR-DIST-07); the workflow engine
  will churn hardest in its first two releases. Publish the node API when it
  has survived one minor version unchanged.
- **PRD § 13.4 — refund autonomy:** confirmed as a product decision, not a
  caution. "The AI cannot issue refunds; it prepares them for your one-click
  approval" is a *selling line* to exactly the merchant anxiety competitors
  trip on (FR-SUPPORT-08 stands).

---

## 4. The Moat, Ranked

In order of durability:

1. **The extension API as ecosystem gravity** (FR-DIST-03/07). Every
   third-party agent or tool built on `storecrew_api_ready` is switching cost
   we did not pay for. This compounds; nothing else on this list does.
2. **In-process architecture.** Not copyable by the SaaS set without
   rebuilding their companies; copyable by WordPress-native rivals only after
   they build orchestration, tools, retrieval, and the security boundary —
   the quarters-long road this repo has already walked.
3. **Trust artefacts.** The audit trail, approval queue, probe-tested
   guards, and the inspector that explains every answer. Invisible in a
   feature-table comparison, decisive in month three of ownership.
4. **Zero-marginal-cost free tier.** An acquisition engine competitors with
   hosted inference cannot run at break-even.

What is *not* a moat: model quality (everyone rents the same models), prompt
craft (leaks instantly), and the brand (R-BRAND-01 makes it a liability to
manage, not an asset to lean on — see § 5.4).

---

## 5. Go-to-Market

### 5.1 Channel: WordPress.org is the strategy, everything else is support

The .org directory is where WooCommerce merchants already look, ranks plugin
pages by installs/ratings/freshness, and costs nothing. The free plugin is
built to be *rateable*: the PRD's time-to-value metric (≤ 15 minutes) is a
review-generation target as much as a UX one.

Sequencing:

1. **Private beta** (Phase 1 complete): 20–30 stores recruited from Decent
   Themes' existing user base; goal is the adversarial-use suite (R-SEC-02)
   meeting real traffic, and the first testimonial set.
2. **.org launch**: free plugin only. No Pro on sale until free has ≥ 500
   installs and a 4.5+ rating — selling into an empty install base wastes the
   launch's one moment of attention.
3. **Pro launch** via Decent Themes' own store (FR-DIST-08 self-hosted
   updates), announced to the installed base in-product (FR-DIST-10 limits:
   prompts may invite, never degrade).
4. **Agency program** once ≥ 20 organic agency installs are observed — let
   demand reveal itself before building the partner machinery.

### 5.2 Demand generation

- **SEO on the jobs, not the category.** "WooCommerce abandoned cart
  recovery", "WooCommerce support automation", "WooCommerce AI product
  search" — job-phrase queries with commercial intent, where the Shopify-first
  competitors' content does not rank. Avoid the head term "AI chatbot",
  which the `Store*` cluster (R-BRAND-01) contests and which attracts the
  wrong buyer anyway.
- **Comparison pages** against the SaaS set on the three structural
  differences (data residency, live store state, no usage markup) — arguments
  that remain true regardless of feature churn on their side.
- **The WooCommerce agency channel** as the compounding loop: agencies
  install free on client stores as retainer value, clients grow, agencies
  bring the Agency-tier deal. Everything in § 2.3's agency persona feeds
  this.

### 5.3 The BYO-key cliff (PRD § 3.4, A1)

The honest weakness. Mitigations in order of leverage:

1. **Onboarding treats the key as step one of five, not a prerequisite**
   (FR-ADMIN-02): provider signup deep-links, paste-and-verify with the live
   `verify()` check, and a cost expectation ("a typical store spends under $5
   a month") backed by the spend cap (FR-AI-06) so the promise is enforced,
   not hoped.
2. **Agencies absorb the friction** for the least technical merchants — one
   more reason the agency channel is strategic rather than opportunistic.
3. **⚖️ PRD § 13.2 — hosted proxy:** recommendation: *design nothing now,
   decide at Phase 3 with data.* If onboarding completion (target ≥ 65%)
   comes in materially under target *and* drop-off concentrates on the key
   step, a Decent Themes-operated inference proxy becomes a Pro feature worth
   its billing complexity. Deliverable 10 should sketch the seam (provider
   abstraction already isolates it) but build none of it.

### 5.4 Brand posture under R-BRAND-01

The name is settled; the strategy manages its costs. The launch domain must
differ from `storecrew.com` (candidates at Gate 5 per the PRD risk register);
all SEO investment goes into job-phrase queries rather than the contested
brand cluster; and the trademark search (USPTO/EUIPO Class 9 + 42) is
commissioned **before Pro launch** — the point at which a forced rename stops
being an inconvenience and starts being a refund event.

### 5.5 ⚖️ White-label depth (PRD § 13.3)

**Recommendation: reskin, not vendor rebrand, in v1.** Logo, colours, product
name in the SPA and widget — yes. Renaming the plugin directory, text domain,
or update source — no: it breaks the update chain (FR-DIST-08) and creates
support-attribution chaos ("which plugin is this really?"). Full rebrand is a
future Agency-tier upsell if partners demand it with money.

---

## 6. Roadmap as Strategy

Phases restate the PRD § 6 scope with the strategic *why*:

| Phase | Ships | Strategic purpose |
|---|---|---|
| **1 — Trust** | Sales + Support agents, knowledge base, chat surface, admin console, approval queue | Earn the install base and the rating. Nothing ships that can embarrass a merchant unattended. |
| **2 — Revenue** | Marketing + Analytics agents, workflows, ESP integrations, Pro launch | Convert trust into subscriptions. Every Phase 2 feature must trace to money the merchant can see. |
| **3 — Scale** | Agency program, custom agent builder, MCP, hosted-proxy decision, further agents | Compound through partners and ecosystem. |

The gate discipline (docs/README.md) maps onto this: no Phase 2 code before
Phase 1 has real merchants, no partner machinery before organic agency demand.

---

## 7. Measurement

The PRD § 11 metrics stand. Strategy adds the *leading* indicators each one
needs, so a miss is diagnosable before it is a quarter old:

| PRD metric | Leading indicator | Instrument |
|---|---|---|
| Onboarding completion ≥ 65% | Drop-off per onboarding step, especially the key step | Step events in usage metering (no external analytics — FR-DIST-11 posture: consented, local) |
| Free → Pro ≥ 4% | % of free stores hitting the conversation cap; % opening the Marketing upsell panel | Cap-hit events; SPA route views, stored locally |
| Deflection ≥ 55% | Escalation rate per conversation topic | Already recorded (`escalated_at`, run outcomes) |
| NRR ≥ 100% | Agency seat expansion; Pro renewal cohort curves | License server data (Deliverable 10) |

One strategy-level tripwire beyond the PRD: **if 90 days post-.org-launch the
install curve is flat and reviews cite setup difficulty, the hosted-proxy
decision (§ 5.3) moves from Phase 3 to immediately.** That is the scenario in
which A1 is false and the strategy's cheapest assumption fails; it is written
down now so the signal is not rationalised away later.

---

## 8. What We Are Deliberately Not Doing

Restating PRD § 9 through the strategy lens — each a temptation that will
recur, refused for a reason:

- **No multi-channel inbox** (WhatsApp, Messenger): different buyer, hosted
  infrastructure requirements, and a crowded incumbent set. The storefront is
  where our structural advantage exists.
- **No non-Woo platforms**: "for WooCommerce" is the positioning; a Shopify
  port would strip every in-process advantage and meet the SaaS set on their
  ground.
- **No local model inference**: the support matrix would be unbounded; BYO
  key already gives cost control.
- **No per-conversation billing, ever**: § 3.4. The moment we meter revenue
  on usage, the positioning sentence in PRD § 4 becomes false advertising.

---

## 9. Gate 1 Decision List

**Ratified at the Gate 1 review, 2026-08-07: all ten decisions approved as
recommended, without amendment.** The table below is now the record of what
was decided, not a proposal. The recommendations marked ⚖️ throughout this
document are therefore settled positions.

| # | Decision | Recommendation | § |
|---|---|---|---|
| D1 | Free-tier meter | Conversations, 100/mo, tunable config | 3.2 |
| D2 | Sales agent placement | Free | 3.3 |
| D3 | Pro price | $199/yr intro (list $249) | 3.4 |
| D4 | Agency price/shape | $599/yr, 25 sites, reskin-level white-label | 3.4, 5.5 |
| D5 | Premium plugin count | One, tiered entitlements | 3.5 |
| D6 | Workflow node API | Premium-internal until stable | 3.5 |
| D7 | Refund autonomy | Confirmed product decision (FR-SUPPORT-08) | 3.5 |
| D8 | Hosted proxy | Decide Phase 3 with data; tripwire at 90 days | 5.3, 7 |
| D9 | Launch sequencing | Beta → .org free → Pro at 500 installs/4.5★ → Agency on demand signal | 5.1 |
| D10 | Launch domain | Candidates at Gate 5; trademark search before Pro launch | 5.4 |

---

## 10. Traceability

| This document consumes | It informs |
|---|---|
| 01-prd.md § 3–5, § 11–13 (market, personas, metrics, open questions) | 10 SaaS Subscription Architecture (tier shapes, license shapes, meter) |
| 15-free-premium-split.md § 2, § 9 (the line, open questions) | 14 Milestone Plan (phase ordering, launch gates) |
| FR-LIC-02..06, FR-DIST-01..12 | 11 Wireframes (onboarding flow, upsell surfaces) |

Decisions D1–D10 were ratified 2026-08-07 and are recorded in § 9 with the
gate date. None changed a requirement — D1 confirms FR-LIC-02 and D7 confirms
FR-SUPPORT-08 as written — so the PRD edits were limited to marking its § 13
open questions resolved; every requirement ID is preserved.
