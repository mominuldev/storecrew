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
   the executor. Disjoint allow-lists per agent. *(Probes: sales cannot
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
- **Conversation data is the merchant's**: retention pruning and GDPR
  erasure paths exist at the repository layer
  (`delete_for_conversation`); the session token is stored only as a
  digest; identity verification stores *which order was proven*, never the
  email offered.
- Provider processing is disclosed to the merchant: their key, their DPA
  with the provider; the architecture's job is that nothing flows there
  except what a turn requires.

---

## 10. Before Launch (the honest list)

- **Adversarial suite v2** (R-SEC-02 mitigation, PRD): scripted injection
  corpus — hostile product reviews, hostile policy pages, hostile order
  notes — run against a live model, asserting tool-denial rather than model
  virtue. Today's probes prove the boundary with a scripted model; the
  live-model corpus is the remaining step.
- **Streaming (FR-CHAT-02)** must reuse this chapter unchanged: SSE alters
  transport, not authority — a design constraint on 09 § 6's future
  `stream()`.
- **`Pro\Licence` replacement** before any Pro ship (10 § 8).
- Third-party review of `SecretStore`'s cryptography before 1.0.
