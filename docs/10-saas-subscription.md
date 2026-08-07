# 10 — SaaS Subscription Architecture

**Product:** StoreCrew AI
**Status:** Draft complete — design; mostly **unbuilt** (see § 8)
**Version:** 0.1

Unlike 03–09, this document designs ahead of code: the only licensing that
exists today is `FeatureGate` (built, tested) and a `Pro\Licence` stub that
must not ship. It consumes strategy decisions D1–D5 (02 § 9) as inputs; if
Gate 1 amends those, the tier table here changes but the machinery does not —
that separation is the design's main claim.

---

## 1. Principles

1. **Entitlement is resolved on the merchant's server, sold from ours.**
   "Server-authoritative" (FR-LIC-03, FR-DIST-09) means *the WordPress
   server*, not a remote call on the request path. Licence state is a locally
   stored, periodically revalidated fact; no customer-facing request ever
   waits on our infrastructure.
2. **The free plugin never phones home** (WordPress.org rule, 15 § 8). All
   remote licence traffic lives in premium. Free computes free-tier truth
   from local state alone.
3. **A lapsed licence degrades to free, never below it** (FR-LIC-06). Data
   is never destroyed or held hostage; premium-created rows persist
   (FR-DIST-06); export always works.
4. **The meter is configuration, not code** (R-MKT-01). Tier shapes live in
   the entitlement payload so pricing experiments never require a plugin
   release.

---

## 2. Tiers (per D1–D4, ratified at Gate 1)

Two different mechanisms live below, and mixing them in one grid is how the
first draft of this section went wrong. **Feature entitlements are booleans
`FeatureGate` already resolves. Quotas are numbers nothing reads yet.**

### 2.1 Feature entitlements

The keys are the slugs registered through `storecrew_register_features` —
literally the strings `FeatureGate::enabled()` is called with. Copy them from
the registry; never paraphrase or pluralise. **An unknown slug evaluates as
not-entitled**, so a snapshot that spells one differently is not an error the
merchant sees: it is a correctly-signed payload that grants a paying customer
nothing, silently, in their disfavour. (`FeatureGate::enabled()` does warn on
an unregistered slug — but only under `WP_DEBUG`, and a *registered* slug that
the snapshot spells differently never reaches that path at all: the gate is
never asked the wrong name, it is simply never told the right one is granted.)

| Entitlement key (registered slug) | Free | Pro | Agency |
|---|---|---|---|
| `agent.sales`, `agent.support`, `knowledge.base`, `chat.widget` | ✓ | ✓ | ✓ |
| `agent.marketing`, `agent.analytics` | – | ✓ | ✓ |
| `workflow.builder` | – | ✓ | ✓ |
| `integrations.email` | – | ✓ | ✓ |
| `agency.multisite` | – | – | ✓ (25 sites) |
| `agency.whitelabel` | – | – | ✓ (reskin) |

The free rows are registered by the free plugin and are ✓ at every tier by
construction: a lapsed licence degrades **to** free, never below it (§ 1.3).
The custom agent builder (02 § 5.1, Phase 3) has **no registered slug** — it
is unbuilt, and inventing one here would be the same defect as misspelling a
real one. It joins this table when Pro registers it.

### 2.2 Quotas

A quota is a number, and `FeatureGate` reads no numbers anywhere — it maps
slugs to booleans by tier and that is all it does. These keys travel in the
snapshot, but the reader that enforces them is unbuilt (§ 5, § 8, 14 § M4):

| Quota key | Free | Pro | Agency |
|---|---|---|---|
| `conversations.monthly` | 100 | `null` (fair-use) | `null` |
| `sites` | 1 | 1 | 25 |

`FeatureGate` maps feature slugs → tiers today; the entitlement payload (§ 4)
toggles which tier the gate evaluates as. That is the whole of what exists —
the quota half needs both a reader and a metric to read (§ 5).

---

## 3. Components

```
Merchant's WordPress                      Decent Themes
┌──────────────────────────┐              ┌──────────────────────────┐
│ free: FeatureGate        │              │ Licence server           │
│   ↑ entitlement snapshot │   activate/  │  keys, activations,      │
│ pro: LicenceClient ──────┼──validate───▶│  entitlements, signing   │
│ pro: Updater ────────────┼──packages───▶│ Update server            │
└──────────────────────────┘              │ (both behind the store)  │
                                          └──────────────────────────┘
```

The licence server is deliberately boring: issue keys on purchase (the store
webhooks it), record activations per site, answer validation with a
**signed entitlement snapshot**, revoke on refund. It holds no store data —
the privacy posture (02 § 2.2) applies to us too.

---

## 4. The Entitlement Snapshot

The unit of trust. Returned by activation and each revalidation:

```json
{
  "licence": "sc_pro_…",  "tier": "pro",  "status": "active",
  "site": "https://shop.example",  "seats": {"used": 1, "max": 1},
  "entitlements": { "agent.marketing": true, "conversations.monthly": null, … },
  "issued_at": "…", "valid_until": "…+14d",
  "signature": "ed25519:…"
}
```

- **Signed (Ed25519), verified locally** with a public key shipped in
  premium. `FeatureGate` accepts entitlements only from a
  signature-valid snapshot — this is what makes FR-LIC-03 true even though
  evaluation is local: editing the stored option breaks the signature and
  the gate falls back to free.
- **`valid_until` is the grace mechanism** (FR-LIC-01): snapshots outlive
  their revalidation interval (weekly check, 14-day validity), so our
  server being down never lapses a paying customer. Expired snapshot →
  admin notice → 7 further days → degrade to free. Clock tampering is not
  worth defending: the licence is not DRM (§ 7).
- Premium hands the snapshot to free through an extension-API filter;
  free's `FeatureGate` stays the single evaluation point, and the SPA's
  manifest remains a rendering hint re-checked per request (FR-DIST-09).
- **The filter is grant-only, and stays that way.** The built stub already
  answers `storecrew_feature_enabled` per feature and only ever returns true,
  because an expired Pro licence must never switch off a free-tier agent —
  the same one-direction reasoning as `storecrew_tool_authorized`, which may
  only deny. The snapshot changes *what premium grants*, not the shape of the
  filter: `LicenceClient` replaces the stub's local option with a
  signature-verified answer to the same question, and a feature free owns is
  never one of premium's to withdraw.

---

## 5. Metering the Free Tier (FR-LIC-02, D1)

**What exists is per-run counting, which is not this.** `UsageRepository`
records every agent run against its conversation, and a single conversation
produces many runs — routing, the answer, each retry. The quota unit here is
the *conversation*, and `UsageRepository::METRIC_CONVERSATION` is declared
and recorded by nothing (Gate 3; 14 § M4.1). A cap enforced today would be
enforced against a counter that is permanently zero, which is the pricing
rule's failure mode inverted: the merchant believes a limit protects the
plan, and it never trips. The meter adds:

- **A conversation consumes quota when it first receives an agent answer**
  — not on open (widget boots must stay free of side effects), not per
  message (a clarifying question must not cost quota).
- At cap: the *widget* declines new conversations politely
  ("chat is busy — email us"); open conversations finish. Admin surfaces
  show the count all month, not only at the cliff (R-MKT-01
  instrumentation).
- The counter is local truth. It is never reported to Decent Themes —
  consented telemetry, if ever added, is a separate opt-in (FR-DIST-11).

---

## 6. Purchase, Activation, Updates

- **Purchase** on the Decent Themes store (existing WooCommerce +
  subscriptions); webhook provisions the key; email + account page deliver
  it.
- **Activation** in the premium plugin's settings panel: paste key →
  activate → snapshot stored. Deactivation releases the seat. Agency keys
  activate up to `seats.max` sites; the account page lists and can remotely
  release activations (FR-LIC-04).
- **Updates** (FR-DIST-08): premium checks the update server with its key;
  entitled sites get the package; lapsed licences keep the installed
  version working (§ 1.3) but stop receiving updates. The .org directory
  updates free independently, and the handshake (FR-DIST-04) protects
  against version skew between the two.
- **Renewal/refund**: subscription renewal re-signs a fresh snapshot on next
  validation; refund revokes → next validation degrades to free with the
  data intact.

---

## 7. Threat Model, Honestly

Licence checks in GPL WordPress code are **compliance furniture, not
security**. Anyone can null the client; the strategy accepts this — the
product's paid value increasingly lives in updates, integrations, and
support, which cracked copies do not receive. What we *do* defend:

- **Entitlement forgery via the API**: impossible without the signing key
  (§ 4) — a bypass requires editing code, not data, which keeps honest
  customers honest and keeps FR-LIC-03's real target (a tampered browser
  manifest, a crafted REST call) closed.
- **Key sharing**: seat counting with visible activations.
- **Our own outage harming customers**: the grace design (§ 4).

What we explicitly do not do: obfuscation, kill-switches, or anything that
could take a paying store down on a network failure — R-COST-01's lesson
(never fabricate safety) applied to licensing.

---

## 8. Build Status and Order

| Piece | Status | Phase |
|---|---|---|
| `FeatureGate`, tier mapping, manifest, server-side re-checks | ✅ Built, probe-tested | — |
| Conversation counting substrate | ⬜ **Not built.** `UsageRepository` meters per *run*; `METRIC_CONVERSATION` is declared and written nowhere (Gate 3), so the § 5 meter would count zero forever | 2 — 14 § M4.1, before any tier depends on it |
| Quota reader (`conversations.monthly`, `sites`) | ⬜ `FeatureGate` resolves booleans only; nothing reads a number | 2 — with the meter above |
| Cap enforcement at the widget | ⬜ | 2 — with Pro launch (free is uncapped until premium exists to sell) |
| Licence server + store webhook | ⬜ | 2 |
| `LicenceClient` (replace the stub), snapshot verification | ⬜ | 2 — **ship-blocking for Pro**; the stub is not a security boundary |
| Update server + premium updater | ⬜ | 2 |
| Agency seats, remote release, white-label flag | ⬜ | 3 |

The stub's replacement is the first premium engineering task; everything
else in premium can be built behind it but not shipped before it.
