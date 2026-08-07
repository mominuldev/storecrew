# Gate 5 Review — Findings

**Date:** 2026-08-07
**Scope:** 12 Security Architecture, 13 Scalability Plan, 14 Development
Milestone Plan.
**Method:** 12's rule of evidence is its own review method — every mechanism
it names as probe-tested was traced to the suite that violates it, and every
"structurally unreachable" claim to the seam that makes it so. 13's numbers
were traced to their measurement harness and its documented runs; its
"exists today" claims to the classes named. 14 was checked against the Gate
2–4 review outcomes it promises to carry, and its counts against the suites
as they stand after the Gate 4 remediation.

**Verdict: approvable after three wording remediations, applied the same
day.** No code defect. This is the first gate since 1 where the code
survived its documents: the boundary probes 12 cites all exist and fire
(spot-traced: the deny-only filter grant attempt, the burst throttle, the
indistinguishable identity failures, ciphertext tamper and rotation refusal,
the planted-transcript rejection, the decided-approval 409), 13's measured
figures match the recall harness's recorded runs, and 14 § M1 already
carries every open ticket the three earlier gate reviews moved into it.

## Findings

| ID | Finding | Class |
|---|---|---|
| **G5-1** | **13 § 2 described retention as built.** "Retention pruning deletes transcripts by age … run/tool-call records prune on the same schedule" — but the hourly sweep prunes only the **audit log** by window; it abandons stale conversations and reaps stalled runs, and deletes nothing else by age. 14 § M1 states this correctly as an open exit criterion, so the two documents disagreed and the generous one was wrong. The claim is the same shape Gate 4's G4-S3 caught in 10: a capability's *substrate* reported as the capability. | Doc-stale |
| **G5-2** | **12 § 9's "retention pruning and GDPR erasure paths exist at the repository layer" blurred the same line.** Repository-level deletion primitives exist (`delete_for_conversation`), and audit pruning runs; the scheduled per-table windows and the WordPress personal-data exporter/eraser integration are M1 tickets (Gate 2). In the one document whose claims get copied into compliance answers, "paths exist" must not be readable as "retention is enforced". | Doc-precision |
| **G5-3** | **12 § 2 "Disjoint allow-lists per agent" lapsed when Gate 3 wired handoff.** Both shipped agents now declare `agent.handoff`, deliberately. The security-relevant statement — Sales holds no order tool, Support no catalogue search — is still true and is what the probes assert; the word "disjoint" no longer is. | Doc-stale |

Also corrected: 14 § M0's assertion count (615 → 616 + 33 browser) and its
"nine suites" line, which predate the Gate 4 remediation that added the
browser harness it elsewhere requires.

## Remediation — same day

All four edits applied: 13 § 2 now states what the sweep does today and
points transcript/run retention at 14 § M1; 12 § 9 names the primitives and
the M1 boundary explicitly; 12 § 2 states the sensitive-set disjointness and
the shared handoff tool; 14's counts current.

## What verified cleanly

**12** — the threat-model ordering covers every risk-register entry it
claims (T1–T7 ↔ R-SEC-01/02, R-COST-01, plus the .org and add-on surfaces);
every named probe exists in a suite that this session has run green; the
injection stance ("worthless at the authority layer") matches the built
order of checks in `ToolExecutor` exactly; § 10's before-launch list agrees
with 14 § M1/M2 item for item. **13** — the scan-latency and recall figures
match `tools/measure-recall.php`'s recorded 2026-08-07 runs; the 2,000-chunk
threshold, dimension lever, and repository seam are as coded; the honest
boundary in § 5 contradicts nothing elsewhere in the set. **14** — every
open ticket from the Gate 2, 3, and 4 reviews appears with an exit
criterion; the sequencing gates match strategy D9; no date appears anywhere
in the plan.

**Gate 5: ✅ approved 2026-08-07.** With it, all fifteen deliverables are
approved at v1.0. The standing obligation that outlives the gates: a change
that alters documented behaviour edits the document in the same change-set,
and the M1 exit criteria are now the single list between here and beta.
