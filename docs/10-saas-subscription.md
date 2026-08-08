# 10 — SaaS Subscription Architecture

**Product:** StoreCrew AI
**Status:** Gate 4 approved 2026-08-07 — design ahead of code, and still
partly ahead of it: the merchant-side half is **built** (meter, quota
reader, cap — § 5, § 8) and `Snapshot`/`LicenceClient` replaced the stub,
but the remote half is not (licence server, `LicenceClient::PUBLIC_KEY`,
updates)
**Version:** 1.0

Unlike 03–09, this document designed ahead of code. What exists today
(all built 2026-08-08, probe-tested): `FeatureGate` (earlier), the § 5
meter with its quota reader and cap enforcement, and the § 4/§ 6
`Snapshot`/`LicenceClient` pair that replaced the stub — verified against
fixture-signed envelopes, awaiting only the server (§ 6.1) and its public
key. It consumes strategy decisions D1–D5 (02 § 9) as inputs; if Gate 1
amends those, the tier table here changes but the machinery does not —
that separation is the design's main claim, and it is now structural: a
tier change is an entitlements-payload change, because grants come from
the snapshot's map, never from a tier lookup in code.

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
snapshot; the reader is `Licensing\Quota` (built — § 5, § 8), which today
reads `conversations.monthly` alone (`sites` waits for its consumer):

| Quota key | Free | Pro | Agency |
|---|---|---|---|
| `conversations.monthly` | 100 | `null` (fair-use) | `null` |
| `sites` | 1 | 1 | 25 |

`FeatureGate` maps feature slugs → tiers; the entitlement payload (§ 4)
toggles which tier the gate evaluates as. The quota half now has both its
reader and a metric that is actually written (§ 5); what it still lacks is
the signed snapshot as a *source* — until `LicenceClient` exists, the only
writer of a non-free quota is the `storecrew_quota` filter itself.

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
- **The signature covers bytes, not structure** (built 2026-08-08). On the
  wire and in storage the snapshot travels as an envelope —
  `{ "payload": base64(JSON bytes), "signature": "ed25519:" + base64(sig) }`
  — with the detached signature over the *decoded payload bytes*, and the
  stored option is that envelope verbatim, never re-encoded. Schemes that
  sign "the JSON minus the signature field" require canonical
  re-serialisation on both sides, and every whitespace, key-order, or
  escaping difference between the server's encoder and PHP's becomes a
  paying customer whose licence stopped verifying. `Pro\Licensing\Snapshot`
  is the only reader; it fails closed (any malformed field → null → free),
  and the fixture probes tamper one byte and watch it die.
- **`valid_until` is the grace mechanism** (FR-LIC-01): snapshots outlive
  their revalidation interval (weekly check, 14-day validity), so our
  server being down never lapses a paying customer. Expired snapshot →
  admin notice → 7 further days → degrade to free. Clock tampering is not
  worth defending: the licence is not DRM (§ 7).
- Premium hands the snapshot to free through an extension-API filter;
  free's `FeatureGate` stays the single evaluation point, and the SPA's
  manifest remains a rendering hint re-checked per request (FR-DIST-09).
- **The filter is grant-only, and stays that way.** Pro's
  `storecrew_feature_enabled` callback only ever returns true, because an
  expired Pro licence must never switch off a free-tier agent — the same
  one-direction reasoning as `storecrew_tool_authorized`, which may only
  deny. As built, the grant consults the verified snapshot's **entitlements
  map by slug** (§ 2.1), never a tier lookup in code: a snapshot that
  misspells a slug grants nothing, which the harness probes on purpose, and
  a feature free owns is never one of premium's to withdraw. The
  `storecrew_quota` callback follows the same shape — passthrough unless a
  granting snapshot names the key.

---

## 5. Metering the Free Tier (FR-LIC-02, D1)

**Built and probe-tested, 2026-08-08** (14 § M4.1's substrate). The quota
unit is the *conversation* — per-run counting long predated this and is not
it: a single conversation produces many runs (routing, the answer, each
retry), which is why a cap enforced against any pre-existing counter would
have been wrong. As built:

- **A conversation consumes quota when it first receives an agent answer**
  — not on open (widget boots must stay free of side effects), not per
  message (a clarifying question must not cost quota), and **not on a turn
  that failed or refused** — those are metered as what they are, and quota
  is only spent when the customer got an answer. Written by
  `ChatService::send()` through `UsageRepository::record_conversation()`,
  which is idempotent per conversation (a NOT EXISTS insert against the
  event log), so the second answered turn charges nothing.
- **The reader is `Licensing\Quota`**, deliberately separate from
  `FeatureGate` (§ 2.2's boolean/number split, kept structural). Free
  default: `conversations.monthly` = 100. The `storecrew_quota` filter is
  **loosen-only** — null means unlimited, and a value below the free tier's
  own number is clamped back up, the same one-direction contract as
  `storecrew_feature_enabled` (§ 4): a lapsed licence degrades **to** free,
  never below it. An unknown quota key is unlimited, loudly under
  `WP_DEBUG` — the opposite default from FeatureGate, because an invented
  limit would cap a storefront against a number nobody chose (the
  fabricated-figure rule applied to limits).
- At cap: **`POST /chat/session` refuses to open a *new* conversation**
  (503 `at_capacity`; the widget renders the boot-delivered `atCapacity`
  string — "chat is busy, email us"). Resume is checked before the cap and
  the message POST is never gated, so open conversations finish; quota being
  spent on first answer, a conversation admitted just under the cap may
  finish just over it — rounding that errs toward the customer. The
  Overview shows `used / limit` all month via `/health`, not only at the
  cliff (R-MKT-01 instrumentation).
- The counter is local truth. It is never reported to Decent Themes —
  consented telemetry, if ever added, is a separate opt-in (FR-DIST-11).

Until premium ships a validated licence that filters the quota, the free
plugin's 100/month is the only limit that exists — which is the launch
sequencing § 8 always intended (free was uncapped only while there was no
premium to sell).

---

## 6. Purchase, Activation, Updates

- **Purchase** on the Decent Themes store (existing WooCommerce +
  subscriptions); webhook provisions the key; email + account page deliver
  it.
- **Activation** on the admin console's Settings → Licence tab (built
  2026-08-08 as a `/licence` route, moved into Settings the same day —
  configuration belongs with configuration, not in the sidebar): paste key
  → activate → snapshot stored; the pane shows the masked key, plan, site,
  validity, and grace as a status pill and detail rows in the shell's own
  card language, and deactivation releases the seat. The pane is Pro's own
  — delivered through the extension seam (a contributed REST controller and
  a plain-JS bundle on the shell's settings-tab registry, 06 § 2.3) — and
  deliberately **not** feature-gated: an activation form that renders as
  "part of a paid plan" is a door that locks its own key inside. Agency
  keys activate up to `seats.max` sites; the account page lists and can
  remotely release activations (FR-LIC-04).
- **Updates** (FR-DIST-08): premium checks the update server with its key;
  entitled sites get the package; lapsed licences keep the installed
  version working (§ 1.3) but stop receiving updates. The .org directory
  updates free independently, and the handshake (FR-DIST-04) protects
  against version skew between the two.
- **Renewal/refund**: subscription renewal re-signs a fresh snapshot on next
  validation; refund revokes → next validation degrades to free with the
  data intact. **A revocation is a signed snapshot whose `status` says so**,
  never an absence or an error: the client treats an unreachable or
  unverifiable validation response as our problem (stored snapshot stays,
  grace decides), so the only thing that may end entitlement early is a
  statement the server provably made.

### 6.1 The server contract (fixed by the client, 2026-08-08)

`Pro\Licensing\LicenceClient` is built and probe-tested against
fixture-signed envelopes; the licence server, when stood up, must implement
what the client already speaks:

- Root `https://decentthemes.com/wp-json/storecrew-licence/v1` (filterable
  client-side via `storecrew_pro_licence_server` for staging).
- `POST /activate`, `POST /validate`, `POST /deactivate` — JSON body
  `{ "key": "...", "site": "https://..." }`.
- Success: HTTP 200 with the § 4 envelope. Failure: non-200 with
  `{ "code": "...", "message": "..." }`; the client surfaces `code` verbatim.
- Signing: Ed25519 detached over the payload bytes
  (`sodium_crypto_sign_detached`); the keypair is generated when the server
  is stood up and the secret half never leaves it.
  `LicenceClient::PUBLIC_KEY` (base64, 32 bytes) is set in the same release —
  **it is empty today, which is fail-closed and ship-blocking**: with no key
  nothing verifies, every answer is free, and the status word is
  `unconfigured` rather than a mystery.
- The client revalidates weekly (WP-Cron `storecrew_pro_licence_revalidate`)
  and stores whatever verifies — including a revocation.
- `POST /update-check` (the updater, built 2026-08-08) — JSON body
  `{ "key", "site", "version", "php", "wp" }` → 200 with
  `{ "version", "package", "url", "requires", "requires_php", "tested" }`.
  The **server** decides entitlement: an entitled licence gets an https
  `package` URL; a lapsed one gets `"package": null`, so the site still
  *sees* that the release exists (with a renewal sentence under the update
  row) without being handed it — nothing installed ever stops working
  (§ 1.3). Update metadata is deliberately **not** signed, unlike the
  snapshot: a snapshot is stored locally and read back as authority, while
  update metadata lives in WordPress's own update transient, which anyone
  able to write options can already use to install code — its trust is the
  TLS transport, same as core's updates. The client discards any answer
  whose `package` is not https, whole.

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
| Conversation counting substrate | ✅ **Built, probe-tested** (2026-08-08): `record_conversation()` written on first agent *answer*, idempotent per conversation; failed turns charge nothing | — |
| Quota reader (`conversations.monthly`) | ✅ **Built, probe-tested**: `Licensing\Quota`, loosen-only `storecrew_quota` filter, null = unlimited. `sites` joins when something reads it — inventing the key now would be the built-but-unconsumed defect | — (`sites`: 3) |
| Cap enforcement at the widget | ✅ **Built, probe-tested**: `/chat/session` refuses new conversations at cap; resume and send never gated; count on the Overview all month | — |
| Licence server + store webhook | ⬜ Must implement § 6.1, which the built client fixes | 2 |
| `LicenceClient` (replace the stub), snapshot verification | ✅ **Built, probe-tested against fixture-signed envelopes** (2026-08-08): Ed25519 envelope verification failing closed, grace to the second, site binding, activation/revalidation/deactivation over an injectable transport, weekly cron, grant-from-entitlements-map, quota loosening. The stub is gone. **Still ship-blocking:** `PUBLIC_KEY` is empty until the server exists — fail-closed (`unconfigured`), probed | 2 |
| Update server + premium updater | ◐ **Client half built, probe-tested** (2026-08-08): `Update URI` header locks WordPress.org out of the slug and routes checks to `Licensing\Updater`; no key = no request; lapsed = release visible, package withheld, reason shown; non-https package poisons the whole answer. The server implements § 6.1's `/update-check` | 2 |
| Agency seats, remote release, white-label flag | ⬜ | 3 |

The stub's replacement was the first premium engineering task, and it is
done up to the wire: what remains of the spine is standing the server up
(§ 6.1) and setting the public key, then the updater.
