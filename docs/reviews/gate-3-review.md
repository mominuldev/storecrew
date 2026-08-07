# Gate 3 Review — Findings

**Date:** 2026-08-07
**Scope:** 08 Agent Framework Architecture, 09 AI Provider Architecture.
**Method:** two independent verification passes, one per document, checking
every falsifiable claim against `src/`; the agent, provider, and knowledge
suites re-run green (80 + 73 + 59). Claims about the live behaviour of
external provider APIs (Anthropic's 400s, model availability, prices) were
deliberately **not** confirmed from memory — the code was verified to match
the documents, and the external truths are listed as unverifiable with the
dates the code carries.

> **Outcomes (2026-08-07, same day):** all nine code findings are **fixed and
> probe-tested**, per the product owner's decisions (D1: handoff wired as the
> `agent.handoff` tool; D2: `cost_known` as a first-class column via
> Migration002 — the migration machinery's first real firing, which worked;
> D3: FR-AGENT-09 rescoped to persona with the gap recorded). G3-C1–C9 all
> landed; the four missing regression guards exist (`thoughtSignature`
> round-trip, mid-turn identity propagation, OpenRouter/DeepSeek shaping,
> GET retry); and the § 2/§ 3 doc edits are applied to 08/09/15/03/12/04/07
> with the FR-AGENT-09 scope note in 01. The suites grew 583 → **615**
> assertions, green in a shuffled run order.
>
> Chasing the order-dependence found something worse than a flake: the
> "unconfigured store" probes in verify-rest and verify-jobs only ever
> passed because **verify-providers' cleanup was deleting the merchant's
> real provider keys on every run** — and once the real key was restored,
> the verify-jobs probe ran the embed job against it: a live, billable call
> from inside a test. Both are fixed (snapshot-and-restore of the secrets
> and data-key options; probes now construct the unconfigured state and
> restore it), and both lessons are in CLAUDE.md's bug table.

**Verdict: not approvable as written.** Nothing found is a security defect —
the ToolExecutor boundary, the deny-only filter, identity gating, the
allow-list check, guardrail ordering, and the one-order verification rule all
hold in code and in probes, and 09's structural claims verify end to end.
The problems are of two kinds: **capabilities the documents present as
working that no production path exercises** (the same shape as Gate 2's
provenance defect, surviving because that sweep scoped 03/04/05/07), and
**08 having missed the Gate 2 remediation pass entirely** — it is dated
2026-08-07 but describes the code from three changes earlier.

---

## 1. Code defects and gaps (the doc is right or silent; the code is not)

| ID | Finding | Evidence |
|---|---|---|
| **G3-C1** | **Handoff is dormant.** `Orchestrator::handoff()` — the mechanism behind FR-AGENT-03 and 08 § 2's headline — has zero callers: no service, route, or tool invokes it, and `ROLE_HANDOFF` messages are never written. Meanwhile Sales' guardrail tells the model to "offer to hand them over", promising the customer a transfer that cannot happen. The machinery (note, context carrying, action) is built and unit-covered; only the trigger is missing. | `src/Agent/Orchestrator.php`, `src/Agent/CoreAgents.php` |
| **G3-C2** | **Touched-product tracking is unwired.** `SharedContext::saw_product()` / `product_ids()` have zero callers in `src/` *and* `tests/` — `ProductSearchTool` never records what it surfaced, so every run carries an empty product list. Identical pattern to Gate 2's G2-C1. | `src/Agent/SharedContext.php`, `src/Agent/Tools/ProductSearchTool.php` |
| **G3-C3** | **`cost_known` is never persisted.** 08 § 3 and 03 § 11 claim runs record "cost — or explicitly cost-unknown, never zero", and `AgentTurn` carries the flag — but `agent_runs` has no column for it and the inspector exposes only `costMicros`, so an unknown-rate model is indistinguishable from a free one in exactly the surface the claim names (FR-ADMIN-04). The Indexer surfaces `costKnown`; the pattern simply never reached runs. Contradicts the repo's own pricing-honesty rule. | `src/Agent/AgentRunner.php`, `src/Api/Rest/Controllers/ConversationController.php` |
| **G3-C4** | **Refused and failed turns are never metered.** The refusal path calls `finish()` but not `meter()`; the provider-failure path meters nothing. Answered and budget-exceeded turns meter. Tokens a refused turn burned never reach `usage_events`, so SpendGuard and the dashboard under-count — the asymmetry is unexplained. | `src/Agent/AgentRunner.php` |
| **G3-C5** | **An invented tool name leaves a permanently-pending row.** The recorded call's id is discarded and `finish()` never runs, so the inspector shows a hallucinated call as *pending* rather than *error* — weakening 08 § 4's "a denied attempt is as visible as a success". (It does not pollute the approval queue; read intent forces `AUTH_AUTO`.) | `src/Agent/Tool/ToolExecutor.php` |
| **G3-C6** | **The provider's own error code is extracted, then discarded.** `agent_runs.error_code` stores the stringified HTTP status; `ProviderException::error_code()` (the provider vocabulary the transport parses out) has zero callers. Operationally 404-vs-429 still distinguishes — but the richer code the transport went to the trouble of extracting never lands. | `src/Agent/AgentRunner.php`, `src/Ai/Exception/ProviderException.php` |
| **G3-C7** | **`get_json()` has no retry.** The full jittered-backoff loop exists only on `post_json()`; credential verification (four of five providers) is single-attempt, so a transient 503 during `verify()` reads like a rejected key. Cheap to re-click, but the doc says "retries with jittered backoff" unqualified. | `src/Ai/Http/HttpClient.php` |
| **G3-C8** | **OpenRouter's default model list ships a `gemini-2.5` id** — the generation 09 itself records as dead for new Gemini keys. Defensible (OpenRouter proxies and may still serve it) but the lists were plainly not refreshed together on 2026-08-07. | `src/Ai/Providers/OpenRouterProvider.php` |
| **G3-C9** | **`SpendGuard::status()` fires the breach action on reads.** `status()` computes `blocked` via `allows_call()`, which under `warn` fires `storecrew_spend_cap_exceeded` as a side effect — every `/health` or `/settings` GET past the cap emits a breach event. | `src/Ai/SpendGuard.php` |

**Test-hygiene defects (same class Gate 2 closed once already):**

- **`verify-chat` → `verify-jobs` is order-dependent**: chat leaves no pending
  chunks, so jobs' "blocked embedding is announced" probe reproducibly fails
  in that order. "Green in any run order" is false again, for a new pairing.
- **Gemini `thoughtSignature` replay has no regression test** — 09's flagship
  live-found fact, guarded by nothing; a refactor could silently reintroduce
  the 400.
- **Mid-turn identity propagation has no probe** — 08 § 5's most
  security-weighted property rests on one unrepeatable live run. The
  retrieval-provenance probe added at Gate 2 is the exact template.
- **OpenRouter and DeepSeek have zero test coverage** of the only surfaces
  they override (attribution headers, base URL) — 09's "every provider driven
  against a recording double" overcounts by two.

---

## 2. 08 missed the Gate 2 remediation pass

08 is dated 2026-08-07 but predates commit `2a425f3`. Three mechanisms it
should describe are absent: the `storecrew_retrieval_performed` provenance
listener (the second instance of the listener pattern 08 § 5 presents as an
identity one-off — architecturally load-bearing for this document
specifically), tool-argument redaction at the recording step, and the
spend-guard check in routing (08 § 2 lists three fallthrough-to-default
paths; the code has five, including fewer-than-two-agents and the capped
store).

---

## 3. Doc-stale edits

**08 Agent Framework** — § 4 numbers the `storecrew_tool_authorized` filter
as step 6, *after* write approval; in code the filter runs inside
`authorise()`, **before** the write-approval branch. No security impact (the
deny-only invariant is untouched; a denied call never reaches approval), but
the order is wrong as written — and the same wrong order appears in
`ToolExecutor`'s own docblock, 03 § 7's list, 12's list, and the CHANGELOG:
fix all in one pass. § 1's merchant-override row should note that
`agent_configs.guardrails` and `.model_policy` are stored but consumed by
nothing (FR-AGENT-09's guardrails half is unimplemented — belongs in § 8).
§ 2's "ear-warmer routed to Sales" citation folds two separate live sessions
into one. § 8 gains: handoff dormant (G3-C1), product tracking unwired
(G3-C2), pending the owner's decisions below.

**09 AI Provider Architecture** — § 1 misstates the interface contract:
`default_models()` lives on `ChatProviderInterface` (and
`default_embedding_models()` on the embedding side), not on the base — the
code's placement is the correct one. § 1's capability field list omits
`streaming` and `effort`, both load-bearing elsewhere in the doc; note that
`streaming: true` describes the *provider* truthfully while the layer cannot
yet consume it (`ChatRequest::$stream` is read by nobody). § 3's "encoded in
a provider class **and asserted by a suite**" overclaims for the
`thoughtSignature` row (no assertion exists — see tests above). § 1's "the
settings validator refuses at save time" is behaviourally right but the
guard lives in `SettingsController`, not `ModelPolicy::save()`, which
persists anything unvalidated — worth naming so an add-on author does not
assume the policy object self-defends. § 5 predates the routing spend-guard
site. § 5's FR-LIC-02 "substrate" sentence is true as written but reads as
built — `METRIC_CONVERSATION` is recorded nowhere yet.

**15 Free/Premium Split (Gate 1-approved — edit with IDs preserved)** —
§ 4.3 names three of 08's four observed actions wrongly
(`storecrew_agent_run_completed` / `storecrew_tool_executed` /
`storecrew_escalated` do not exist; the real names are
`storecrew_agent_turn_completed`, `storecrew_tool_failed`,
`storecrew_conversation_escalated`) and § 4.2 lists three filters that exist
nowhere in code (`storecrew_agent_system_prompt`, `storecrew_agent_route`,
`storecrew_conversation_context`). 08 is right; 15 is stale. An add-on
author following 15 today would hook actions that never fire.

---

## 4. Decisions for the product owner

| ID | Decision |
|---|---|
| **G3-D1** | **Handoff (G3-C1): wire a trigger now, or declare it a § 8 gap?** Wiring needs a mechanism decision (a handoff tool the model can call, or orchestrator-side detection); declaring it a gap requires softening Sales' "offer to hand them over" guardrail so the model stops promising transfers it cannot make. The second is honest and cheap; the first is the feature. |
| **G3-D2** | **`cost_known` persistence (G3-C3): schema change or meta?** A first-class column needs Migration002 (the first real test of the migration machinery); tucking it into an existing JSON column avoids that but hides it from indexing. |
| **G3-D3** | **FR-AGENT-09's guardrails half:** implement merchant guardrail overrides (consumed nowhere today) or amend the PRD requirement's scope note. The config surface already stores them, so the gap is silent. |

---

## What verified cleanly (the short list)

08: agent value-object contract and prompt composition order (guardrails
after persona, probe-tested against a hostile persona); the full ToolExecutor
order semantics (deny-only filter both directions, reads never queue, writes
default to a human, disabled wins outright); identity — timing-safe compare,
one-order rule, indistinguishable failures byte-for-byte, 5-attempt cap,
mid-turn listener detached in `finally`, revocation on customer change;
TurnBudget 8/32k/45s checked before tools run; both § 8 gaps real (no
streaming; `fallback()` zero callers); the shipped-agents table accurate line
by line; all four extension actions named correctly; 2 agents / 5 tools.
09: the three-interface split and Anthropic-only-resolves-embeddings-to-null;
per-provider request shaping including no-sampling-ever on Anthropic, the
three tool-dialect normalisations, Gemini task types and signature replay
(implemented); ModelPolicy resolution and `fallback()`'s zero callers;
SpendGuard semantics incl. the new routing site; Pricing unknown-never-zero
with `known` propagation and Anthropic-only seeded rates (RATES_VERIFIED
2026-06-24, surfaced in /settings); HttpClient retry/backoff/Retry-After on
POST; SecretStore envelope encryption, master-key ladder, health surfacing,
rewrap-vs-rotate semantics with partial-rotation refusal, masked hints,
key-free audit rows; five providers registered unconditionally, frozen at 20.
All cross-references from both docs resolve except 15 § 4 (above). External
API truths (model ids, prices, Anthropic 400s, Gemini quirks) are recorded
as unverifiable-by-design with their in-code as-of dates.
