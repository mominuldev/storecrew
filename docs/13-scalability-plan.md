# 13 — Scalability Plan

**Product:** StoreCrew AI
**Status:** Draft complete — 2026-08-07
**Version:** 0.1

Scale for a self-hosted plugin means something different than for a SaaS:
there is no fleet to grow — **every store must scale on whatever hosting it
already has**, and the operator is a merchant, not an SRE. The plan is
therefore organised by the four things that actually grow: the catalogue,
the conversation volume, the job load, and the money. Wherever a number
exists, it is measured, not guessed; where a cliff exists, it is named with
its contingency.

---

## 1. The Catalogue (retrieval scale — R-TECH-01)

**Measured baseline** (`tools/measure-recall.php`, 2026-08-07): cosine over
a 1536-dim packed vector ≈ 90 µs; full dense scan ≈ 91 ms @ 1k chunks,
454 ms @ 5k, 13.6 s @ 150k. Recall@3 0.96 at dense-only on the 62-chunk
fixture corpus; the lexical arm *hurts* (0.80) and is weighted out by
default.

**The tiers as shipped:**

| Corpus | Strategy | Status |
|---|---|---|
| < 2,000 chunks | Full dense scan every query | Measured; comfortably in budget (≤ 300 ms p95 target) |
| 2,000 – ~50k | Lexical prefilter → dense re-rank | Ships, but **known-weaker** (0.80 vs 1.00 recall@3): FULLTEXT cannot match "warm hat" to "Beanie" at any candidate limit |
| Beyond, or when the mid-tier's recall is unacceptable | External vector index | **The R-TECH-01 contingency — designed-for, not built** |

The seam for the contingency is already load-bearing: nothing outside
`KnowledgeChunkRepository` knows vectors are float32 LONGBLOBs (03 § 4), so
an external store (SQLite-vec on-host, or a merchant-supplied Qdrant/pgvector)
is one repository implementation, chosen by capability detection. Decision
trigger: the first real catalogue where the mid-tier's measured recall on
*its* fixtures fails the 0.88 bar — measured, because the fixture design
(shopper phrasing ≠ catalogue phrasing) is the only honest test.

**Index size lever:** dimensions are configuration (default 1536; Gemini
native 3072 = 12 KB/vector — double the table for marginal recall).
5k products ≈ 7k chunks ≈ 45 MB of vectors at 1536: fine in InnoDB. 50k
products is where the external index conversation starts regardless of
latency, for backup-size reasons alone.

**Two structural exclusions keep the index small and stable:** volatile
fields never enter it (FR-KB-08 — also why bulk stock edits re-embed
nothing), and SKU/exact-identifier lookup will be its own cheap tool rather
than a semantic query (known gap; it fails at every fusion weight).

---

## 2. Conversations (data growth and the hot path)

- **Hot-path queries are index-shaped**: session lookup by token digest,
  customer resume by `(customer_id, status)`, transcript by
  `(conversation_id, id)` — all covered (04). The prompt window is the last
  20 turns, so a long conversation costs a bounded read and a bounded
  prompt, not a growing one.
- **Growth is pruned, not accumulated**: `MaintenanceJob` abandons idle
  conversations; retention pruning deletes transcripts by age (merchant
  policy, GDPR-aligned — 12 § 9). Run/tool-call records prune on the same
  schedule; the audit log retains longest (it is the incident record).
- **Storefront overhead when idle is near zero**: a page load without the
  widget adds ≤ 2 queries (PRD budget); with the widget it adds one async
  script and zero queries until someone speaks (conversations open on first
  message).
- **Rate limits are the concurrency valve** (05 § 3): per-session and
  per-IP windows cap the *arrival rate* of model work per store;
  `TurnBudget` caps the *depth* of each unit. Together they bound worst-case
  concurrent provider calls without any queue in the chat path.

The deliberate non-goal: chat turns are synchronous request/response
(45 s wall-clock ceiling). Queuing turns would survive slower hosts but
break the product (a shopper will not wait behind a cron). The mitigation
for slow hosts is model choice (routing on a fast tier) and, when built,
streaming's perceived latency win (FR-CHAT-02).

---

## 3. Background Work (indexing at catalogue scale — R-TECH-03)

Shared hosting kills long processes; the design assumes it:

- Jobs are **chunked, resumable, keyset-paginated** — `ids()` reaches the
  database with a real cursor (the first bug this project fixed was a cursor
  that silently stopped at ~20 objects). A kill mid-run loses one chunk's
  work, not the run.
- **Liveness is heartbeat**, not status (`status = running` is what a killed
  process leaves behind); stale runs are swept and resumed.
- **Deadline** bounds each execution under the host's kill window;
  Action Scheduler (bundled with Woo — A2) provides the retry spine; the
  scheduler-cancel quirk (no group on cancel) is encoded.
- **Embedding batches** are the unit of provider spend and of resume:
  a 50k-chunk initial index is thousands of batches over hours — acceptable
  because it is *resumable and pre-estimated* (`/index/estimate`,
  R-COST-01), and incremental thereafter (FR-KB-07: an edit re-indexes one
  object; a model/width change self-heals gradually).
- **Capability detection** (FR-CORE-07) reports what the host can actually
  do — CLI vs web PHP mismatch, cron reliability — instead of failing
  mysteriously. Validation against a real budget host is a pre-launch task
  (14), not an assumption.

---

## 4. Spend (the scale axis merchants feel first)

Cost grows with conversations × turn depth × model tier, and every term has
a governor: `SpendGuard`'s hard monthly cap checked *before* each call;
`TurnBudget` per turn; routing on a cheap tier by design (08 § 2); pricing
that refuses to fabricate (unknown ≠ zero); the pre-flight index estimate;
and the free tier's conversation meter as the commercial bound (10 § 5).
The dashboard shows spend against cap all month — R-COST-01's mitigation is
visibility as much as enforcement.

---

## 5. Multi-Store (Agency) and the Ceiling Above

Agency scale is *many independent stores*, not one big one — each site owns
its data, index, and spend; nothing is shared, so nothing new must scale.
The genuinely hard ceilings above this plan — a 500k-SKU catalogue, a
storefront doing thousands of concurrent chats — are hosted-infrastructure
problems (dedicated vector service, connection pooling) and belong to the
hosted-proxy decision (02 § 5.3, D8), not to the self-hosted plugin. The
plan's honest boundary: **StoreCrew scales with the merchant's hosting up
through the Growing Store Owner persona; beyond that, the architecture has
seams, and the strategy has a tripwire.**

---

## 6. Standing Measurements

What must be re-measured, and when:

| Measurement | Harness | Trigger |
|---|---|---|
| recall@3/@5 on fixtures | `measure-recall.php` | Any retrieval change; any embedding model change; first 10k-chunk real corpus (the unmeasured case) |
| Scan latency vs corpus size | same | Before raising the 2,000-chunk threshold |
| Index runtime + cost on a budget host | manual, real host | Before .org launch (R-TECH-03) |
| Storefront added queries / widget budget | suite + build output | Every release (45 KB gz budget; at 5.3) |
| p95 turn latency by model tier | run records (built-in) | Continuously; drives model-policy defaults |
