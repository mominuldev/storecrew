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

## 2. Tiers (per D1–D4, pending Gate 1)

| Entitlement key | Free | Pro | Agency |
|---|---|---|---|
| `agents.marketing`, `agents.analytics`, `agents.custom` | – | ✓ | ✓ |
| `workflows`, `integrations.esp` | – | ✓ | ✓ |
| `conversations.monthly` | 100 | `null` (fair-use) | `null` |
| `sites` | 1 | 1 | 25 |
| `whitelabel` | – | – | reskin |

`FeatureGate` already maps feature slugs → tiers; the entitlement payload
(§ 4) simply toggles which tier the gate evaluates as.

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
  "entitlements": { "agents.marketing": true, "conversations.monthly": null, … },
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

---

## 5. Metering the Free Tier (FR-LIC-02, D1)

Counting already exists: `UsageRepository` records every agent run per
conversation. The meter adds:

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
| Conversation counting substrate | ✅ Built (`UsageRepository`) | — |
| Cap enforcement at the widget | ⬜ | 2 — with Pro launch (free is uncapped until premium exists to sell) |
| Licence server + store webhook | ⬜ | 2 |
| `LicenceClient` (replace the stub), snapshot verification | ⬜ | 2 — **ship-blocking for Pro**; the stub is not a security boundary |
| Update server + premium updater | ⬜ | 2 |
| Agency seats, remote release, white-label flag | ⬜ | 3 |

The stub's replacement is the first premium engineering task; everything
else in premium can be built behind it but not shipped before it.
