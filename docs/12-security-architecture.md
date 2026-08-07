# 12 — Security Architecture

**Product:** StoreCrew AI
**Status:** Draft complete — consolidates the built posture as of 2026-08-07
**Version:** 0.1

This document consolidates security decisions made throughout 03–09 into one
threat-model-ordered view. Its rule of evidence is the project's own: **a
guard that has never been observed to fire is not a guard.** Every mechanism
below is either structurally unreachable or probe-tested — the suites
deliberately violate it and assert the refusal. Where a probe exists, it is
named.

---

## 1. Threat Model

Ordered by the risk register (PRD § 12):

| # | Threat | Actor | Asset |
|---|---|---|---|
| T1 | Prompt injection via indexed content or tool output escalates to unauthorised tool use (R-SEC-01) | Anyone who can author a product review, page, or order note | Store writes, order data |
| T2 | Order/customer data disclosed without verification (R-SEC-02) | Anonymous shopper, or holder of a leaked conversation uuid | PII, order history |
| T3 | Provider key theft | DB dump, backup leak, malicious admin-adjacent code | The merchant's API key and wallet |
| T4 | The store's own session driven cross-site | Third-party page abusing the merchant's logged-in cookie | Admin API |
| T5 | Enumeration and abuse of the public chat surface | Scripts, competitors, the bored | Conversation data, model spend |
| T6 | A malicious or buggy add-on widens authority | Installed plugin code | Everything above |
| T7 | Unbounded spend (R-COST-01) | A runaway loop, a verbose model, an abuser | The merchant's wallet |

Out of scope, stated plainly: a hostile WordPress administrator (they own
the process), and licence piracy (10 § 7 — compliance furniture, not
security).

---

## 2. T1 — Prompt Injection

The stance: **injection is assumed to succeed at the language layer and must
be worthless at the authority layer.** No defence below tries to *detect*
injection; every defence removes what a successful one could do.

1. Retrieved content enters prompts as **user-role** text, never system
   (08 § 1); the composed prompt instructs that tool content is data.
2. The agent's `tool_ids` allow-list bounds reach *before* the security
   boundary — Support persuaded to create a coupon fails at the agent, not
   the executor. The **sensitive tool sets are disjoint** — Sales holds no
   order tool, Support no catalogue search; the one deliberate overlap is
   `agent.handoff`, which transfers a conversation and grants nothing.
   *(Probes: sales cannot
   look up orders / write notes.)*
3. `ToolExecutor` authorises from `ToolContext` — built from the
   conversation row and WordPress session, **never from model output or tool
   arguments**. A model claiming `identity_verified: true` is claiming it
   into a field nobody reads.
4. The `storecrew_tool_authorized` filter **may only deny** — ANDed with the
   prior decision. *(Probe: a filter returning true against a capability
   denial stays denied.)*
5. Writes default to human approval (FR-AGENT-05); reads never queue, so the
   approval queue stays meaningful. *(Probes: unconfigured write tool
   parks pending; approving a decided call 409s.)*
6. Client-supplied history is ignored — transcripts rebuild from the
   database (03 § 8). *(Probe: a planted "identity verified" assistant turn
   never reaches the model.)*
7. Model output reaches the shopper's DOM as text nodes only; links require
   a parsed http(s) scheme (06 § 3.2).
8. Guardrails compose *after* the merchant persona. *(Probe: "ignore all
   previous instructions" persona leaves the price guardrail intact.)*

Residual risk: injection can still waste a turn's budget or produce a bad
*answer* within policy. `TurnBudget` bounds the first; the inspector makes
the second explainable and the escalation path (FR-SUPPORT-07) makes it
recoverable.

---

## 3. T2 — Order Data and Identity

- Order-class tools declare `requires_identity`; the executor refuses before
  execution. *(Probe: unverified context denied, tool never ran.)*
- `identity.verify` proves **one order**: order number + billing email
  (timing-safe compare), or a logged-in session owning the order.
  `order.lookup` refuses any other order even when verified. *(Probes:
  wrong email fails; unknown order and wrong email are one indistinguishable
  sentence — no existence oracle; proven order readable, neighbour not.)*
- Attempts capped per conversation (5); failures audited with salted IP
  hash — the walk-the-order-table signal a merchant needs.
- Verification is conversation state; changing the logged-in customer
  **revokes** it (shared-device case, 04's conversation row).
- Public surface: uuid is an address, token is the credential, and unknown
  vs unowned conversations return the same 404 (05 § 3). *(Probes: stranger
  with uuid refused read and write; tokenless refused; superseded token dies
  on cross-device rebind.)*

---

## 4. T3 — Secrets

`SecretStore` (09 § 7): envelope encryption; master from WP salts; data-key
rotation refuses on any undecryptable secret; tampered ciphertext refuses.
*(Probes: tamper detection; rotation refusal.)* The REST layer returns only
a masked hint — *(probe: no fragment of a stored key appears in any
response or audit row).* Keys are never logged; the audit row for a key save
records the act, not the material.

---

## 5. T4 — The Admin API

Deny-by-default controller base: every route declares a capability;
forgetting produces a locked route, not an open one (05 § 1.2). Cookie +
nonce for writes — the nonce is what stops a third-party page driving the
API with the merchant's session (06 § 2.2). Entitlement re-checked
server-side per request; the SPA manifest is a hint (FR-DIST-09). *(Probes:
every route dispatched unauthenticated and refused; subscriber refused; the
denied key-write asserted to have not landed.)*

Standard WordPress hygiene throughout: `$wpdb->prepare` on every repository
query (the only `$wpdb` consumers — 03 § 4), escaping at output, sanitised
settings with type-aware rules (an accent colour that is really a stylesheet
is rejected — *probed*), no dynamic SQL identifiers outside `Tables`.

---

## 6. T5 — The Public Surface

Rate limiting per session *and* per hashed IP (05 § 3) — consumed only on
speech, so reads never spend the allowance; refusals keep counting, so
hammering after a 429 is what the IP backstop exists for. Message length
capped (413). Conversations open on first message, not page load. Sequential
ids never exposed (04; 05 § 1.3). Widget failure yields absence, not
information (FR-CHAT-03). *(Probes: burst throttled with retry-after;
oversized and empty messages refused before the model; closed conversation
409s.)*

---

## 7. T6 — Add-ons

The extension API is designed so a contributor can *narrow* but never
*widen*: registries freeze after the window *(probe: late write throws)*;
the authorisation filter is deny-only (§ 2.4); contributed controllers
inherit deny-by-default; and the free plugin contains zero references to
premium (enforced, 15 § 7), so free's security review is closed under its
own tree. A malformed migration contribution is filtered out rather than
taking the schema down (03 § 4).

---

## 8. T7 — Spend

`SpendGuard` gates before every call; `TurnBudget` bounds each turn from
outside with exhaustion as a recorded outcome; pricing reports unknown,
never zero — a fabricated figure is a cap that never trips (09 § 5). The
free-tier meter (10 § 5) is a second, commercial bound on the same
substrate. *(Probes: budget stops a scripted runaway loop; estimate reports
costKnown false with no rates.)*

---

## 9. Privacy

- **No egress from the free plugin** except merchant-configured provider
  calls; no telemetry without consent (FR-DIST-11); no webfonts.
- **IPs are never stored raw** — salted SHA-256 in audit and rate-limit
  state; recognition without identification.
- **Conversation data is the merchant's, and retention is enforced**
  (04 § 11, implemented and probe-tested): all four windows prune from the
  hourly sweep — conversations cascade to everything they own, pending
  approvals are exempt from any window, and sub-floor settings clamp up
  rather than silently off. The WordPress personal-data exporter and eraser
  are registered: export excludes operator notes; erasure severs
  `customer_id`, the proven order, and the session binding (no surviving
  cookie resumes the thread) and blanks message content while counters
  survive. The session token is stored only as a digest; identity
  verification stores *which order was proven*, never the email offered.
- Provider processing is disclosed to the merchant: their key, their DPA
  with the provider; the architecture's job is that nothing flows there
  except what a turn requires.

---

## 10. Before Launch (the honest list)

- ~~**Adversarial suite v2**~~ (R-SEC-02 mitigation, PRD) — built
  2026-08-08, `tests/schema/verify-adversarial.php`. A named injection corpus
  — hostile product reviews, policy pages, order notes, and product
  descriptions, each written to escalate a model into an unauthorised tool
  call — delivered through the real untrusted-input channel (a tool-role
  result) and asserted to die at a boundary. It runs through two drivers
  against **one corpus and one set of boundary assertions**:
  - A **compliant** scripted model that obeys every injection to the letter.
    This always runs, needs no key, and is the CI-able proof: when an
    injection fully succeeds at the language layer, the authority layer still
    refuses. Every one of the six boundaries — identity gate,
    authority-is-not-model-supplied, one-identity-one-order confinement,
    write-waits-for-a-human, invented tool, agent allow-list — is asserted to
    fire, across all four hostile channels.
  - The **live** model the store is configured with, opted into by
    `STORECREW_ADVERSARIAL_LIVE=1`. It reads the same hostile text and decides
    for itself; the suite asserts no breach on any item and reports how many
    attacks actually reached the boundary. A rate-limit refusal or a provider
    outage is a safe non-exercise, never a failure — a 429 is quota, not a
    hole (09 § 3). The customer's own message asks directly for the gated
    action, so a well-aligned model still calls the tool and the boundary
    fires on authority grounds rather than on the model's reluctance.
  Live-observed 2026-08-08: the configured `gemini-3.6-flash` called
  `order.lookup` on an **unverified** conversation exactly as the injected
  review demanded, and the identity gate denied it before execution — an
  attempt dying at a boundary, not at model discretion.
- ~~Streaming~~ — built, and the constraint held: the SSE branch diverges
  from the JSON branch only *after* every guard has run, which is probed
  directly (a rate-limited streaming request is refused as JSON before any
  event starts; the merchant veto and a capability-less provider both fall
  back to buffered). Transport changed; this chapter did not. The buffering-host
  fallback (R-TECH-02) is now probed at the parse layer too: the widget's SSE
  assembler is transport-agnostic by construction, and `tests/browser/sse.spec.mjs`
  drives the shipping code under buffered / streamed / byte-split / CRLF delivery
  to assert all four reach the same events — so a proxy that holds or re-line-ends
  the stream produces the buffered experience, not a broken one.
- **`Pro\Licence` replacement** before any Pro ship (10 § 8).
- Third-party review of `SecretStore`'s cryptography before 1.0.
