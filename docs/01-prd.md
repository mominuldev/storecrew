# 01 — Product Requirements Document

**Product:** StoreCrew AI — The AI Employee Platform for WooCommerce
**Owner:** Decent Themes
**Status:** Gate 1 approved — § 13 open questions resolved by 02 § 9 (D1–D10)
**Version:** 1.0
**Date:** 2026-08-07 (ratified 2026-08-07)

---

## 1. Executive Summary

StoreCrew AI gives a WooCommerce merchant a team of AI employees that work inside their store: a **Sales** employee that finds products and builds offers, a **Support** employee that resolves order and return enquiries end to end, a **Marketing** employee that segments customers and runs campaigns, and an **Analytics** employee that reports on the business and recommends actions.

The distinguishing claim is not "better chatbot." It is that these agents **take actions against the store** — create coupons, register exchange requests, write order notes, build segments, schedule campaigns — under an orchestration layer that lets them share context and hand work to one another.

The product ships as a self-hosted WordPress plugin. All store data stays on the merchant's server. The commercial model is freemium, with paid tiers unlocking agents, automation, and multi-site.

---

## 2. Problem Statement

A WooCommerce merchant running $10k–$250k/month in GMV has the same operational load as a staffed retail business and none of the staff:

| Job to be done | Current reality |
|---|---|
| Answer "where is my order?" | Manual, 40–60% of all support volume, zero revenue value |
| Help a shopper find the right product | Search box + category filters; no interpretation of intent |
| Process a return or exchange | Email thread, manual eligibility check, manual inventory check |
| Recover an abandoned cart | A generic timed email, if configured at all |
| Understand what is actually happening in the business | WooCommerce Analytics tab, read occasionally, acted on rarely |
| Run a campaign | Requires a separate ESP, a segment definition, and copywriting time |

The merchant's options today are: hire staff they cannot afford, buy several disconnected SaaS tools, or do nothing. Most do nothing.

**The gap:** existing AI tools address one slice each (chat, or recommendations, or email) and none of them act on the store. The merchant still does the work; the tool just talks about it.

---

## 3. Competitive Landscape

Screened 2026-08-07. Two distinct competitor sets.

### 3.1 AI commerce SaaS (Shopify-first)

| Product | Positioning | Platform posture |
|---|---|---|
| **StoreClerk** (InfinitAI) | AI sales & support — recommendations, upsell, 24/7 support. $49.99–$199.99/mo. Launched Oct 2024 | Shopify-native; WooCommerce, Magento, BigCommerce via integration |
| **StoreClaw** | "AI agent for ecommerce — run your store 24/7"; store ops, SEO, content | Shopify-first |
| **Storee** | AI Retail Manager, multi-location merchandising. VC-backed (XRC Ventures) | Retail chains, not SMB Woo |
| **Shoptelligence** | AI style discovery & merchandising. Founded 2016, Ann Arbor. Claims +33% AOV | Home furnishings vertical; enterprise |
| **AisleMind** | AI retail intelligence — forecasting, inventory | Regional retail |
| **StorePilot** (several distinct products) | CRO agent / multi-channel operator / store builder | Shopify |
| **Rebuy, Octane AI** | Recommendation & quiz engines | Shopify-only |

**The pattern that matters:** every one of these is a **hosted SaaS built Shopify-first**, reaching WooCommerce — if at all — through a REST integration. That posture forces three things on a Woo merchant: store data egresses to a third party, latency and rate limits sit between the AI and the catalogue, and pricing is per-conversation on top of model cost.

### 3.2 WordPress-native AI plugins

Predominantly single-purpose chat widgets wrapping one provider's API, with a knowledge base built from page scrapes. They run in-process and keep data local, but none implement multi-agent orchestration, none take write actions against WooCommerce, and none carry a commerce data model.

### 3.3 Where StoreCrew wins

1. **In-process, WooCommerce-native.** Direct access to the product, order, and customer models — including HPOS — with no API round trip, no rate limit, and no data egress. Retrieval can read a variation's stock level at request time; a SaaS integration reads a nightly sync.
2. **Actions, not answers.** Agents hold typed, capability-gated tools that write to the store.
3. **A crew, not a bot.** An orchestrator routes work between specialised agents and shares context across them.
4. **Merchant owns the model relationship.** Bring-your-own API key means the merchant pays model cost at cost, not at SaaS markup.

### 3.4 Where StoreCrew is weak

Stated plainly so strategy can address it:

- **No hosted infrastructure at launch.** Embeddings and inference run against the merchant's own key on their own hosting. Shared hosting is hostile to long-running jobs.
- **Setup burden.** BYO API key is a real conversion barrier versus a one-click SaaS install.
- **Brand collision.** See R-BRAND-01.

---

## 4. Vision & Positioning

> **Positioning statement.** For WooCommerce merchants who cannot afford the staff their store's workload demands, StoreCrew AI is an AI employee platform that runs sales, support, marketing, and analytics inside the store itself. Unlike Shopify-first AI SaaS that reaches WooCommerce through an API, StoreCrew runs natively on the merchant's own server, acts directly on store data, and bills no markup on model usage.

**Tagline:** *Your store, fully staffed.*

**Product principles**

1. **An employee does the work.** If an agent can only describe an action, that feature is not finished.
2. **Every action is reversible and auditable.** Anything an agent writes is logged with agent, prompt, tool call, and result.
3. **The merchant is in command.** Every write-capable tool has an approval mode. Autonomy is earned per tool, not granted globally.
4. **Degrade, never break.** No AI failure — provider outage, quota exhaustion, malformed response — may take down the storefront or checkout.
5. **The store's data never leaves without consent.** Every egress is disclosed, configurable, and logged.

---

## 5. Target Users

Summarised here; full treatment in Deliverable 2.

| Persona | Profile | Primary need | Tier |
|---|---|---|---|
| **Solo Merchant** | 1 person, <$10k/mo GMV, 50–500 SKUs | Stop losing evenings to "where is my order" | Free → Pro |
| **Growing Store Owner** | 2–10 staff, $10k–250k/mo, 500–5,000 SKUs | Convert more traffic without adding headcount | **Pro (primary revenue persona)** |
| **WooCommerce Agency** | Builds/maintains 10–100 client stores | A retainer-justifying capability they can white-label | Agency |
| **Store Operations Manager** | Employed at a $250k+/mo merchant | Automate repetitive ops; needs audit and permissions | Agency / SaaS |

Primary design target is the **Growing Store Owner**. They have enough volume for AI to pay for itself, enough pain to seek a tool, and no procurement process.

---

## 6. Product Scope

### 6.1 The Crew

| Agent | Mission | Ships |
|---|---|---|
| **Sales** | Increase conversion rate and AOV | Phase 1 |
| **Support** | Deflect and resolve support volume | Phase 1 |
| **Marketing** | Increase customer lifetime value | Phase 2 |
| **Analytics** | Provide decision-grade business intelligence | Phase 2 |
| Inventory, Operations, SEO, Retention, Product Research | — | Phase 3+ |

### 6.2 Orchestration

A central **Orchestrator** owns conversation state and decides which agent handles a turn. It supports:

- **Routing** — classify intent, dispatch to the right agent
- **Handoff** — an agent transfers a conversation with accumulated context intact
- **Consultation** — an agent queries another agent's tools without transferring the conversation
- **Escalation** — hand off to a human with a generated summary

Shared context is a structured object (session, customer identity, cart, referenced orders and products, agent scratchpad), not a concatenated transcript.

---

## 7. Functional Requirements

IDs are stable and permanent. `MUST` = Phase 1 launch blocker. `SHOULD` = Phase 2. `MAY` = Phase 3+.

### 7.1 Core Platform — `FR-CORE`

| ID | Requirement | Priority |
|---|---|---|
| FR-CORE-01 | Activate cleanly on WordPress 6.6+ / PHP 8.3+ / WooCommerce 9.0+; deactivate with a clear notice if unmet | MUST |
| FR-CORE-02 | Declare HPOS (High-Performance Order Storage) compatibility and read orders through the CRUD/data-store layer only — never direct post-meta queries | MUST |
| FR-CORE-03 | Declare Cart & Checkout Blocks compatibility | MUST |
| FR-CORE-04 | Create and version all custom tables via a migration runner with forward-only, idempotent migrations | MUST |
| FR-CORE-05 | Register capabilities (`storecrew_manage`, `storecrew_view_analytics`, `storecrew_manage_agents`, `storecrew_converse`) mapped to roles at activation | MUST |
| FR-CORE-06 | Background work runs on Action Scheduler (bundled with WooCommerce); no dependence on `wp-cron` alone | MUST |
| FR-CORE-07 | Detect and surface a hosting-capability report at install: PHP version parity between web and CLI SGA, max execution time, memory limit, loopback request success, Action Scheduler health | MUST |
| FR-CORE-08 | Full uninstall removes all tables, options, scheduled actions, and transients; opt-in data retention available | MUST |
| FR-CORE-09 | All user-facing strings translatable under text domain `storecrew`; ship a POT file | MUST |
| FR-CORE-10 | Emit a documented action/filter surface for third-party extension | SHOULD |

### 7.2 Agent Framework — `FR-AGENT`

| ID | Requirement | Priority |
|---|---|---|
| FR-AGENT-01 | Agents are declarative definitions (identity, mission, tools, model policy, guardrails) resolvable from a registry | MUST |
| FR-AGENT-02 | Orchestrator classifies intent and routes to exactly one owning agent per turn | MUST |
| FR-AGENT-03 | Agent-to-agent handoff preserves the structured shared context | MUST |
| FR-AGENT-04 | Tools are typed, individually capability-gated, and declare read/write intent | MUST |
| FR-AGENT-05 | Every write tool supports three modes: `auto`, `approval-required`, `disabled` — configured per tool | MUST |
| FR-AGENT-06 | A hard turn budget (tool calls, tokens, wall-clock) terminates runaway loops and returns a graceful message | MUST |
| FR-AGENT-07 | Every agent run is persisted: agent, model, prompt hash, tool calls, arguments, results, tokens, latency, outcome | MUST |
| FR-AGENT-08 | Consultation — an agent invokes another agent's tools without conversation transfer | SHOULD |
| FR-AGENT-09 | Merchant may edit an agent's persona, tone, and guardrails without code | SHOULD — **rescoped 2026-08-07 (Gate 3): persona and tone ship; guardrail editing deferred.** Shipped guardrails are appended *after* the persona so an edited persona cannot strip them (R-SEC-01); where a merchant's own rule sits in that order, and how it is prevented from loosening one, needs a design pass rather than a column read. The storage exists (`agent_configs.guardrails`); nothing consumes it. See 08 § 8 |
| FR-AGENT-10 | Custom agents definable via the admin UI | MAY |

### 7.3 Sales Agent — `FR-SALES`

| ID | Requirement | Priority |
|---|---|---|
| FR-SALES-01 | Resolve natural-language product intent (attributes, price ceiling, use case) to a ranked result set over the live catalogue | MUST |
| FR-SALES-02 | Respect stock status, backorder settings, and variation-level availability — never recommend an unpurchasable item | MUST |
| FR-SALES-03 | Respect catalogue visibility, product status, and per-user pricing/role restrictions | MUST |
| FR-SALES-04 | Generate cross-sell and upsell suggestions grounded in Woo's linked-product data plus co-purchase history | MUST |
| FR-SALES-05 | Produce side-by-side product comparisons from real attribute data | SHOULD |
| FR-SALES-06 | Create a bundle offer and issue a scoped WooCommerce coupon (write tool, approval-gated by default) | SHOULD |
| FR-SALES-07 | Abandoned-cart recovery: detect, and re-engage via configured channel | SHOULD |
| FR-SALES-08 | Personalise from the identified customer's order history when the visitor is logged in | SHOULD |
| FR-SALES-09 | Never state a price, stock level, or delivery promise not retrieved from store data in the same turn | MUST |

### 7.4 Support Agent — `FR-SUPPORT`

| ID | Requirement | Priority |
|---|---|---|
| FR-SUPPORT-01 | Look up order status with **verified identity** — logged-in session, or order number plus matching billing email | MUST |
| FR-SUPPORT-02 | Never disclose order, customer, or address data without passing identity verification | MUST |
| FR-SUPPORT-03 | Report shipment tracking from Woo order data and common tracking plugins | MUST |
| FR-SUPPORT-04 | Answer policy questions grounded strictly in the merchant's indexed policy documents | MUST |
| FR-SUPPORT-05 | Evaluate return/exchange eligibility against the merchant's configured policy window and product-level exclusions | MUST |
| FR-SUPPORT-06 | Execute the exchange workflow: validate order → check eligibility → verify replacement stock → create request → write order note → notify customer | MUST |
| FR-SUPPORT-07 | Escalate to a human with a structured summary when confidence is low, the customer asks, or a guardrail trips | MUST |
| FR-SUPPORT-08 | Never issue a refund automatically in Phase 1; prepare the refund for one-click human approval | MUST |
| FR-SUPPORT-09 | Maintain a FAQ surface the merchant can curate directly | SHOULD |

### 7.5 Marketing Agent — `FR-MKT`

| ID | Requirement | Priority |
|---|---|---|
| FR-MKT-01 | Build customer segments from Woo order data (RFM, category affinity, lifetime value, lapse window) | SHOULD |
| FR-MKT-02 | Draft campaign copy grounded in real product data | SHOULD |
| FR-MKT-03 | Create WooCommerce coupons with scope, limits, and expiry (approval-gated) | SHOULD |
| FR-MKT-04 | Integrate with Mailchimp, Brevo, FluentCRM, Klaviyo, ActiveCampaign via a common adapter interface | SHOULD |
| FR-MKT-05 | Abandoned-cart recovery sequences with measurable attribution | SHOULD |
| FR-MKT-06 | Never send to a contact without a recorded marketing consent basis | MUST |

### 7.6 Analytics Agent — `FR-ANALYTICS`

| ID | Requirement | Priority |
|---|---|---|
| FR-ANALYTICS-01 | Report revenue, AOV, conversion rate, and top products over selectable ranges, sourced from Woo Analytics tables | SHOULD |
| FR-ANALYTICS-02 | Answer ad-hoc business questions in natural language against a **constrained query surface** — never free-form SQL | MUST |
| FR-ANALYTICS-03 | Attribute revenue influenced by StoreCrew conversations, with methodology stated | SHOULD |
| FR-ANALYTICS-04 | Generate ranked, actionable recommendations with the supporting figures attached | SHOULD |
| FR-ANALYTICS-05 | Inventory forecasting and reorder-point suggestions | MAY |
| FR-ANALYTICS-06 | Every reported figure is traceable to its query; no figure is model-generated | MUST |

### 7.7 Knowledge Base & Retrieval — `FR-KB`

| ID | Requirement | Priority |
|---|---|---|
| FR-KB-01 | Index products, variations, categories, attributes, pages, posts, and uploaded documents | MUST |
| FR-KB-02 | Chunk with configurable size/overlap, preserving structural boundaries | MUST |
| FR-KB-03 | Generate embeddings via the configured provider, batched, resumable, on Action Scheduler | MUST |
| FR-KB-04 | Store vectors in a custom table; retrieve by cosine similarity without requiring a vector-DB dependency | MUST |
| FR-KB-05 | **Hybrid retrieval** — combine dense vector similarity with lexical keyword search; weighting configurable | MUST |
| FR-KB-06 | Embed queries with the provider's query-side task type where the provider distinguishes query from document embeddings | MUST |
| FR-KB-07 | Re-index incrementally on product/post save and on stock change; never require a full rebuild for a single edit | MUST |
| FR-KB-08 | **Volatile fields — price, stock, order status — are read live at request time, never served from the index** | MUST |
| FR-KB-09 | Report retrieval quality against a fixture set with a measured recall figure before launch | MUST |
| FR-KB-10 | Merchant can inspect what was retrieved for any given answer | SHOULD |

### 7.8 Chat Surface — `FR-CHAT`

| ID | Requirement | Priority |
|---|---|---|
| FR-CHAT-01 | Storefront widget loading asynchronously with no render-blocking asset | MUST |
| FR-CHAT-02 | Streamed responses (SSE), with a documented fallback where the host buffers output | MUST |
| FR-CHAT-03 | Widget failure never blocks page render, cart, or checkout | MUST |
| FR-CHAT-04 | Themeable appearance; WCAG 2.2 AA; full keyboard operation | MUST |
| FR-CHAT-05 | Persist conversation across page navigation and across sessions for identified customers | MUST |
| FR-CHAT-06 | Rate limiting and abuse protection per session and per IP | MUST |
| FR-CHAT-07 | Shortcode and block placement in addition to the floating widget | SHOULD |

### 7.9 Workflow Builder — `FR-WF`

| ID | Requirement | Priority |
|---|---|---|
| FR-WF-01 | Visual WHEN/THEN builder over Woo events (order created, status changed, stock low, cart abandoned, review left) | MAY |
| FR-WF-02 | Actions include agent invocation, notification, coupon creation, order note, webhook | MAY |
| FR-WF-03 | Execution is durable, retried on failure, and logged per run | MAY |
| FR-WF-04 | Dry-run mode that reports what would have happened without side effects | MAY |

### 7.10 Admin Application — `FR-ADMIN`

| ID | Requirement | Priority |
|---|---|---|
| FR-ADMIN-01 | Standalone React 19 SPA — **no `@wordpress/*` packages, no Gutenberg dependencies** | MUST |
| FR-ADMIN-02 | Guided onboarding: provider key → source selection → index → agent activation → widget placement | MUST |
| FR-ADMIN-03 | Dashboard: conversations, deflection rate, influenced revenue, index health, job health, token spend | MUST |
| FR-ADMIN-04 | Conversation inspector showing turns, retrieved context, tool calls, and outcomes | MUST |
| FR-ADMIN-05 | Agent configuration: persona, tools, autonomy mode per tool, model policy | MUST |
| FR-ADMIN-06 | Approval queue for pending write actions | MUST |
| FR-ADMIN-07 | Light and dark themes; responsive to 768px | MUST |
| FR-ADMIN-08 | Job and index health visible on the dashboard operators actually open — not buried in a tools page | MUST |

### 7.11 AI Providers — `FR-AI`

| ID | Requirement | Priority |
|---|---|---|
| FR-AI-01 | Support OpenAI, Anthropic (Claude), Google (Gemini), OpenRouter, DeepSeek behind one provider interface | MUST |
| FR-AI-02 | Per-task model policy — routing, chat, embedding, and summarisation independently configurable | MUST |
| FR-AI-03 | API keys encrypted at rest with a **rotatable** key; rotation must not destroy stored secrets | MUST |
| FR-AI-04 | Token accounting per conversation, agent, and provider, with cost estimation | MUST |
| FR-AI-05 | Automatic retry with backoff; failover to a configured secondary provider | MUST |
| FR-AI-06 | Hard monthly spend cap with configurable behaviour on breach | MUST |
| FR-AI-07 | Prompt caching where the provider supports it | SHOULD |
| FR-AI-08 | MCP client support for external tool servers | MAY |

### 7.12 Licensing & Commercial — `FR-LIC`

| ID | Requirement | Priority |
|---|---|---|
| FR-LIC-01 | License key activation with periodic remote validation and a grace period on network failure | MUST |
| FR-LIC-02 | Free tier enforced at 100 AI conversations/month, Sales and Support agents only | MUST |
| FR-LIC-03 | Feature gating is server-authoritative; a client-side bypass must not unlock a paid feature | MUST |
| FR-LIC-04 | Multi-site license management for the Agency tier | SHOULD |
| FR-LIC-05 | White-label branding for the Agency tier | SHOULD |
| FR-LIC-06 | Store remains functional and data remains exportable if a license lapses | MUST |

### 7.13 Distribution & Extensibility — `FR-DIST`

The product ships as two plugins: a free WordPress.org plugin and a license-keyed premium add-on. Full contract in Deliverable 15.

| ID | Requirement | Priority |
|---|---|---|
| FR-DIST-01 | The free plugin is a **complete, independently useful product**. It must never ship disabled, obfuscated, or crippled premium code | MUST |
| FR-DIST-02 | The premium plugin adds capability **exclusively through the published extension API**. No editing, monkey-patching, or reaching into free-plugin internals | MUST |
| FR-DIST-03 | The free plugin publishes a versioned, semver'd extension API covering: agent registration, tool registration, provider registration, REST controller registration, admin route/menu registration, container service registration, and feature gating | MUST |
| FR-DIST-04 | Premium performs a **version handshake** at load: it declares the free-plugin API version range it requires, and self-deactivates with an admin notice if unmet | MUST |
| FR-DIST-05 | With the free plugin absent or inactive, premium must not fatal — it shows an actionable admin notice and registers nothing | MUST |
| FR-DIST-06 | Deactivating premium must leave the free plugin fully functional; premium-created data persists and is not destroyed | MUST |
| FR-DIST-07 | The extension API is documented and public — third parties may build against the same surface premium uses | MUST |
| FR-DIST-08 | Premium ships its own license-gated update mechanism; it is never distributed through WordPress.org | MUST |
| FR-DIST-09 | Feature gating is evaluated server-side against license state; the SPA renders from a server-supplied capability manifest and never decides entitlement itself | MUST |
| FR-DIST-10 | The free plugin may present upgrade prompts, but must not degrade free functionality to manufacture them | MUST |
| FR-DIST-11 | Both plugins comply with WordPress.org plugin guidelines where applicable — no bundled compiled binaries, no external code loading, no tracking without consent | MUST |
| FR-DIST-12 | A single shared admin SPA serves both plugins; premium contributes routes and panels via the extension API rather than shipping a second application | MUST |

---

## 8. Non-Functional Requirements

### 8.1 Compatibility

| Dependency | Minimum | Target |
|---|---|---|
| PHP | 8.3 | 8.4 |
| WordPress | 6.6 | 7.0 |
| WooCommerce | 9.0 | latest |
| MySQL / MariaDB | 8.0 / 10.6 | — |
| Browsers | Last 2 versions, Chrome/Firefox/Safari/Edge | — |

HPOS and Cart/Checkout Blocks compatibility are **mandatory**, not optional.

### 8.2 Performance Budgets

| Metric | Budget |
|---|---|
| Storefront widget JS (gzipped) | ≤ 45 KB |
| Added blocking time to storefront render | 0 ms — widget is fully async |
| Admin SPA initial bundle (gzipped) | ≤ 250 KB |
| Time to first streamed token | ≤ 2.0 s p75 |
| Retrieval latency (10k chunks) | ≤ 300 ms p95 |
| Added DB queries on a non-widget page load | ≤ 2 |
| Peak memory per agent run | ≤ 128 MB |

### 8.3 Reliability

- No AI subsystem failure may raise a PHP fatal on a storefront request.
- Indexing is resumable after timeout or process kill.
- **The plugin must function on shared hosting** where PHP CLI and PHP-FPM may run different versions and long-running processes are killed. Hosting capability is detected and reported (FR-CORE-07), and the queue strategy adapts.

### 8.4 Security & Privacy

- Order and customer data disclosed only after identity verification (FR-SUPPORT-01/02).
- All REST endpoints enforce capability checks and nonce/token validation.
- Prompt-injection defence: retrieved content is untrusted input and can never alter tool authorisation.
- GDPR: data export and erasure hooks; conversation retention configurable; every third-party egress disclosed.
- No store data is transmitted to Decent Themes infrastructure except anonymised, opt-in telemetry.

### 8.5 Accessibility

WCAG 2.2 AA for both the storefront widget and admin SPA: keyboard operation, visible focus, screen-reader announcement of streamed content, respect for `prefers-reduced-motion`.

---

## 9. Out of Scope for v1

- Voice or telephony
- Native mobile apps
- Multi-channel inbox (Messenger, WhatsApp, Instagram)
- Non-WooCommerce e-commerce platforms
- Self-hosted/local model inference
- Automatic refund execution without human approval
- Image generation
- Multi-currency and multi-language *content generation* (UI localisation is in scope)

---

## 10. Assumptions & Dependencies

| # | Assumption | If false |
|---|---|---|
| A1 | Merchants will supply their own provider API key | Conversion suffers; requires hosted proxy — a Phase 3 decision |
| A2 | Action Scheduler is available (bundled with WooCommerce) | Requires an independent queue |
| A3 | MySQL cosine similarity over a custom table is fast enough at 10k–50k chunks | Requires an external vector store; changes hosting story |
| A4 | Streaming (SSE) works on mainstream shared hosting | Fallback to polling; degrades perceived latency |
| A5 | Merchants accept approval-gated writes rather than demanding full autonomy | Revisit default autonomy posture |

A3 and A4 are **material architectural risks** and must be resolved by spike before Gate 2 is approved.

---

## 11. Success Metrics

### Product

| Metric | Target (12 months post-launch) |
|---|---|
| Support deflection rate | ≥ 55% of conversations resolved without human |
| Retrieval recall @5 on fixture set | ≥ 0.88 |
| Conversation → order conversion lift | ≥ 8% vs non-engaged sessions |
| Free → Pro conversion | ≥ 4% |
| Onboarding completion (install → first indexed answer) | ≥ 65% within 24h |
| Median time to first value | ≤ 15 minutes |

### Business

| Metric | Target |
|---|---|
| Active installs (12 mo) | 5,000 |
| Paying customers (12 mo) | 400 |
| Net revenue retention | ≥ 100% |
| Support tickets per paying customer per month | ≤ 0.4 |

---

## 12. Risks

| ID | Risk | Impact | Mitigation |
|---|---|---|---|
| **R-BRAND-01** | Name collision: `storecrew.com` is a live e-commerce site; the `Store*` AI cluster (StoreClerk, StoreClaw, Storee, StoreDNA, StorePilot) is dense. No trademark clearance performed. | High — SEO ownership, possible rename cost, trademark exposure | Accepted by product owner. Launch domain must differ from `storecrew.com` — select at Gate 5. Commission USPTO/EUIPO Class 9 + 42 search before commercial launch. |
| R-TECH-01 | MySQL vector search too slow at scale (A3) | High | Spike before Gate 2; benchmark at 10k/50k/200k chunks |
| R-TECH-02 | SSE unavailable or buffered on common shared hosts (A4) | Medium | Spike before Gate 2; documented polling fallback |
| R-TECH-03 | Shared hosting kills indexing jobs; PHP version differs between web and CLI | High | FR-CORE-07 capability detection; chunked resumable jobs; measure against a real budget host, not just local |
| R-COST-01 | Merchant receives an unexpectedly large provider bill | High — churn and reputation | FR-AI-06 hard spend cap; pre-flight cost estimate before indexing; visible token spend on dashboard |
| R-SEC-01 | Prompt injection via product review or indexed content causes unauthorised tool use | Critical | Retrieved content is untrusted; tool authorisation derives only from session capability, never from model output |
| R-SEC-02 | Order data disclosed to an unverified party | Critical | FR-SUPPORT-01/02; adversarial test suite before launch |
| R-MKT-01 | Free tier at 100 conversations/month is too generous to convert | Medium | Instrument from day one; tier limits are configuration, not code |
| R-DEP-01 | WooCommerce introduces a breaking change (e.g. further HPOS migration) | Medium | Use CRUD/data-store APIs exclusively; run against Woo beta |

---

## 13. Open Questions for Gate 1 Review

**All resolved at the Gate 1 review, 2026-08-07** — decisions recorded in
[02-product-strategy.md](02-product-strategy.md) § 9. Kept here with their
resolutions because downstream documents cite these by number.

1. **Free tier shape** — is 100 conversations/month the right meter, or should it be indexed documents, or agent count? The meter chosen determines the entire usage-tracking schema.
   **Resolved (D1):** conversations, 100/month, as FR-LIC-02 states — the number is tunable configuration, not a promise.
2. **BYO key vs hosted proxy** — is a Decent Themes-operated inference proxy on the Phase 3 roadmap? This decides whether SaaS billing infrastructure is designed now or bolted on later.
   **Resolved (D8):** design nothing now; decide at Phase 3 with onboarding data. Deliverable 10 sketches the seam only. Tripwire: a flat install curve 90 days post-launch with setup-difficulty reviews pulls the decision forward.
3. **Agency white-label depth** — reskin only, or full vendor rebrand including the plugin name in the admin menu?
   **Resolved (D4):** reskin in v1 — logo, colours, product name in SPA and widget. No directory/text-domain/update-source rename.
4. **Refund autonomy** — FR-SUPPORT-08 forbids automatic refunds in Phase 1. Confirm this is a product decision, not just a caution.
   **Resolved (D7):** confirmed as a product decision. "Prepares refunds for one-click approval" is a selling line; FR-SUPPORT-08 stands.
5. **Launch domain** — required to resolve R-BRAND-01.
   **Resolved (D10):** candidates selected at Gate 5; USPTO/EUIPO Class 9 + 42 trademark search commissioned before Pro launch.

---

## 14. Traceability

Every requirement ID in this document is referenced by at least one downstream deliverable:

| Area | Consumed by |
|---|---|
| FR-CORE, FR-KB | 03 Technical Architecture, 04 Database Schema |
| FR-AGENT | 08 Agent Framework Architecture |
| FR-AI | 09 AI Provider Architecture |
| FR-SALES, FR-SUPPORT, FR-MKT, FR-ANALYTICS | 03, 05, 08 |
| FR-CHAT, FR-ADMIN | 06 React Application Structure, 11 Wireframes |
| FR-LIC | 10 SaaS Subscription Architecture |
| FR-DIST | 15 Free/Premium Split & Extension API, 07 Plugin Folder Structure |
| All | 12 Security Architecture, 14 Milestone Plan |

A requirement with no downstream consumer is either out of scope or a documentation gap. This table is verified at each gate.
