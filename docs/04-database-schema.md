# 04 — Database Schema

**Status:** Gate 2 approved — verified column-for-column against the live schema
**Version:** 1.0
**Date:** 2026-08-07 (reviewed, remediated, and approved 2026-08-07)
**Implements:** FR-CORE-04, FR-AGENT-07, FR-KB-01…10, FR-SUPPORT-01/02, FR-AI-04, FR-LIC-02, FR-ADMIN-04/06/08
**Addresses:** R-TECH-01, R-TECH-03, assumption A3

---

## 1. Conventions

| Concern | Decision |
|---|---|
| Prefix | `{$wpdb->prefix}scr_` — e.g. `wp_scr_conversations` |
| Engine | InnoDB, always |
| Charset | `$wpdb->get_charset_collate()` — never hardcoded |
| Primary keys | `BIGINT UNSIGNED AUTO_INCREMENT` |
| Public identifiers | A separate `uuid CHAR(36)`, on conversations only — the one row the public surface must address. An auto-increment ID never reaches a storefront URL, widget payload, or unauthenticated response. Admin REST responses do return run, call, and index-run ids to capability-holding staff: there they are inspector handles, not addresses, and inventing uuids for them would buy nothing |
| Timestamps | `DATETIME` in UTC. Not `TIMESTAMP` — the 2038 limit is inside a plausible support window |
| JSON columns | `LONGTEXT` holding JSON, not the native `JSON` type — MariaDB 10.6 and MySQL 8 disagree on behaviour, and `dbDelta` handles `LONGTEXT` predictably |
| Money | `BIGINT` micros (millionths). No floats anywhere near cost |
| Foreign keys | **None.** `dbDelta` cannot manage them, and a constraint failure during an upgrade would brick a merchant's site. Integrity is enforced in repositories, and orphan sweeps run as scheduled maintenance |
| Booleans | `TINYINT(1) UNSIGNED NOT NULL DEFAULT 0` |

**On WooCommerce data.** No table below duplicates a product, order, or customer record, and nothing joins to a WooCommerce table directly. Order and product access goes through the CRUD/data-store layer, which is what keeps HPOS compatibility real rather than declared (FR-CORE-02). Where a Woo entity is referenced it is stored as a bare ID with no constraint.

---

## 2. Entity Overview

```
conversations ──< messages
      │              │
      │              └──< agent_runs ──< tool_calls
      │
      └──< usage_events ──(rollup)──> usage_counters

knowledge_sources ──< knowledge_chunks

conversations ──< attributions >── (WooCommerce order, by id)

index_runs          audit_log          agent_configs
```

**Twelve** tables in the free plugin. Premium owns four more (§10).

### Reserved-word deviations

Three column names differ from the first draft of this document, changed during implementation and verified against MySQL:

| Draft | Actual | Reason |
|---|---|---|
| `index_runs.cursor` | `index_runs.cursor_position` | `CURSOR` is a **reserved word in MySQL** |
| `tool_calls.authorization` | `tool_calls.auth_mode` | `AUTHORIZATION` is reserved in the SQL standard |
| `knowledge_sources` composite unique on `(source_type, object_id, external_ref(64))` | `source_key char(64)` unique | `dbDelta` handles prefixed index columns unreliably |

Renamed rather than backtick-escaped: a column that needs escaping forever is a trap for every query written after it. `source_key` is a SHA-256 of the identity tuple, which also sidesteps InnoDB index key-length limits under `utf8mb4`.

---

## 3. Conversation Tables

### 3.1 `scr_conversations`

```sql
CREATE TABLE {prefix}scr_conversations (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  uuid              CHAR(36)        NOT NULL,
  session_token     CHAR(64)        NOT NULL DEFAULT '',
  customer_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status            VARCHAR(20)     NOT NULL DEFAULT 'open',
  channel           VARCHAR(32)     NOT NULL DEFAULT 'widget',
  locale            VARCHAR(10)     NOT NULL DEFAULT '',
  identity_verified TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  verified_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  verified_at       DATETIME        DEFAULT NULL,
  message_count     INT UNSIGNED    NOT NULL DEFAULT 0,
  run_count         INT UNSIGNED    NOT NULL DEFAULT 0,
  escalated_at      DATETIME        DEFAULT NULL,
  started_at        DATETIME        NOT NULL,
  last_activity_at  DATETIME        NOT NULL,
  closed_at         DATETIME        DEFAULT NULL,
  meta              LONGTEXT        NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY session_token (session_token),
  KEY customer_id (customer_id),
  KEY status_activity (status, last_activity_at),
  KEY started_at (started_at)
) {charset_collate};
```

`status` ∈ `open | closed | escalated | abandoned`.
`channel` ∈ `widget | shortcode | block | rest`.

**`identity_verified` is a security column, not a convenience flag.** FR-SUPPORT-02 forbids disclosing any order, customer, or address data until identity is proven. It is set only by the verification tool — either a logged-in session, or an order number matched against its billing email. Every order-reading tool checks it. It resets to `0` if `customer_id` changes mid-conversation, so a session cannot inherit a previous visitor's verification on a shared device.

**No raw email is stored here.** Verification compares the supplied address against the order's billing email in memory and records only which order was proven, so a leaked conversations table does not leak a customer list. (The address the model passed as an argument is redacted before the tool call is persisted — § 11.)

### 3.2 `scr_messages`

```sql
CREATE TABLE {prefix}scr_messages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  role            VARCHAR(16)     NOT NULL,
  agent_id        VARCHAR(64)     NOT NULL DEFAULT '',
  content         LONGTEXT        NOT NULL,
  content_format  VARCHAR(16)     NOT NULL DEFAULT 'markdown',
  tokens_in       INT UNSIGNED    NOT NULL DEFAULT 0,
  tokens_out      INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation_seq (conversation_id, id),
  KEY created_at (created_at)
) {charset_collate};
```

`role` ∈ `user | assistant | system | tool | handoff`.

`conversation_seq` is `(conversation_id, id)` rather than `(conversation_id, created_at)` deliberately: `id` is monotonic and unique, so transcript ordering is stable even when two messages land in the same second.

### 3.3 `scr_attributions`

```sql
CREATE TABLE {prefix}scr_attributions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id        BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  basis           VARCHAR(20)     NOT NULL DEFAULT '',
  agent_id        VARCHAR(64)     NOT NULL DEFAULT '',
  minutes_elapsed INT UNSIGNED    NOT NULL DEFAULT 0,
  recorded_at     DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY conversation_id (conversation_id),
  KEY recorded_at (recorded_at)
) {charset_collate};
```

`basis` ∈ `session | customer`. **Implemented** (Migration005), FR-ANALYTICS-03.

**`verified_order_id` on the conversation is not this.** That column records that a customer *proved who they were* against an order they already had — identity verification, pointing backwards. This table points forwards: this shopper talked to the crew and then bought. Nothing recorded that until now, which is why the honest answer to "what did StoreCrew earn me" was silence rather than an estimate.

Three properties are deliberate.

**It holds no money.** No revenue column, no currency, no captured total. The row is the *link*; the amount is read live from the order at report time — the same discipline FR-KB-08 applies to price and stock. A stored total would keep counting a refunded order forever, and a merchant reading revenue WooCommerce no longer agrees with cannot tell which number is wrong.

**`order_id` is unique.** One order credits at most one conversation, so the model is last-touch by construction rather than by convention — and the checkout hooks, which fire twice on a store running both classic and Blocks checkout, are idempotent for free.

**`basis` is stored per row**, so the methodology is auditable against the data rather than only described in prose. A store whose links are all `customer` has cookie trouble, and that is worth being able to see.

Recording happens in `Chat\OrderAttribution` on the two checkout hooks, and only there: `woocommerce_new_order` is deliberately not one of them, because it fires for orders an administrator creates in wp-admin where the session cookie belongs to whoever is staffing the shop. A link requires a **storefront** conversation (§ 7b — a merchant console thread is never a customer's shopping conversation), at least one agent answer in it, and a last activity inside the window (`storecrew_attribution_window_days`, 7 days by default, clamped to 1–90).

The methodology is published from the recorder through `Api\Attribution::methodology()` rather than restated by whatever reports on it, so the description and the mechanism cannot drift. It states what it **cannot** see as well as what it can: the figure is a floor, because a shopper who chats on a phone and buys on a laptop is invisible to it.

---

## 4. Agent Observability

FR-AGENT-07 requires every run persisted. This is what makes the conversation inspector (FR-ADMIN-04) possible, and it is the only way to answer "why did the agent say that" after the fact.

### 4.1 `scr_agent_runs`

```sql
CREATE TABLE {prefix}scr_agent_runs (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  message_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  agent_id        VARCHAR(64)     NOT NULL,
  provider        VARCHAR(32)     NOT NULL DEFAULT '',
  model           VARCHAR(64)     NOT NULL DEFAULT '',
  prompt_hash     CHAR(64)        NOT NULL DEFAULT '',
  status          VARCHAR(24)     NOT NULL DEFAULT 'running',
  tool_call_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  tokens_in       INT UNSIGNED    NOT NULL DEFAULT 0,
  tokens_out      INT UNSIGNED    NOT NULL DEFAULT 0,
  cost_micros     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cost_known      TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  latency_ms      INT UNSIGNED    NOT NULL DEFAULT 0,
  retrieved       LONGTEXT        NULL,
  error_code      VARCHAR(64)     NOT NULL DEFAULT '',
  error_message   TEXT            NULL,
  started_at      DATETIME        NOT NULL,
  finished_at     DATETIME        DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY conversation_id (conversation_id),
  KEY agent_started (agent_id, started_at),
  KEY status (status)
) {charset_collate};
```

`status` ∈ `running | completed | failed | budget_exceeded | timeout | cancelled`.

**`prompt_hash` not the prompt.** System prompts are long, largely identical across runs, and would dominate the table. The hash lets you prove which prompt version produced an answer; the prompt bodies live in `agent_configs`.

**`retrieved` stores chunk IDs and scores, not chunk text** — that satisfies FR-KB-10 (merchant can inspect what was retrieved) without duplicating the corpus into every run row.

`budget_exceeded` is a first-class status because FR-AGENT-06 requires a hard turn budget. A run that hits the ceiling is a recorded outcome, not an error to be swallowed.

**`cost_known` is why `cost_micros` can be trusted** (added by Migration002). `Pricing` reports an unpriced model as unknown rather than as zero — but without somewhere to put that flag, the honesty died at the row boundary and the inspector showed an unrated model as a *free* call. That is the fabricated zero the pricing rule exists to prevent, arriving by omission instead of by invention. Existing rows default to `1`: they were written when only priced models were configured, and marking every historical run as suspect would manufacture doubt the data does not support.

`error_code` holds the HTTP status, enriched with the provider's own code when one was sent (`429:RESOURCE_EXHAUSTED`) — the difference between "rate-limited" and "this model is gone for new keys" is the difference between waiting and reconfiguring, and support needs to tell a merchant which.

### 4.2 `scr_tool_calls`

```sql
CREATE TABLE {prefix}scr_tool_calls (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  agent_run_id  BIGINT UNSIGNED NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL,
  tool_id       VARCHAR(64)     NOT NULL,
  intent        VARCHAR(8)      NOT NULL DEFAULT 'read',
  auth_mode     VARCHAR(16)     NOT NULL DEFAULT 'auto',
  arguments     LONGTEXT        NULL,
  result        LONGTEXT        NULL,
  status        VARCHAR(16)     NOT NULL DEFAULT 'pending',
  approved_by   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  approved_at   DATETIME        DEFAULT NULL,
  duration_ms   INT UNSIGNED    NOT NULL DEFAULT 0,
  error_message TEXT            NULL,
  created_at    DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY agent_run_id (agent_run_id),
  KEY conversation_id (conversation_id),
  KEY approval_queue (auth_mode, status, created_at),
  KEY tool_created (tool_id, created_at)
) {charset_collate};
```

`intent` ∈ `read | write`. `auth_mode` ∈ `auto | required | approved | denied`. `status` ∈ `pending | succeeded | failed | skipped`.

**The approval queue is this table, not a separate one** (FR-ADMIN-06). A pending write is a tool call that has not executed yet; modelling it separately would mean two rows describing one act, and an opportunity for them to disagree about whether it ran. `approval_queue` on `(auth_mode, status, created_at)` serves the queue view directly.

`arguments` and `result` are capped at 65,535 bytes — the `TEXT` ceiling, one byte under 64 KB — before write, and the cap lives in the shared `Repository` base, so **every** JSON column in the plugin carries it, not just these two. The behaviour is replacement, not clipping: an oversized payload is stored as `{"_truncated":true,"_bytes":N}`, because a half-written object that fails to decode later is worse than an honest "this was too big" — and that marker is what an inspector reading the row will see. A tool returning a 5 MB payload is a bug, and the log should not amplify it into a disk problem. (`arguments` also passes through identity redaction before it is written — § 11.)

---

## 5. Knowledge Base

### 5.1 `scr_knowledge_sources`

```sql
CREATE TABLE {prefix}scr_knowledge_sources (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_key    CHAR(64)        NOT NULL,
  source_type   VARCHAR(32)     NOT NULL,
  object_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  external_ref  VARCHAR(191)    NOT NULL DEFAULT '',
  title         TEXT            NULL,
  url           TEXT            NULL,
  content_hash  CHAR(64)        NOT NULL DEFAULT '',
  status        VARCHAR(16)     NOT NULL DEFAULT 'pending',
  chunk_count   INT UNSIGNED    NOT NULL DEFAULT 0,
  error_message TEXT            NULL,
  indexed_at    DATETIME        DEFAULT NULL,
  updated_at    DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_key (source_key),
  KEY source_type_object (source_type, object_id),
  KEY status (status),
  KEY content_hash (content_hash)
) {charset_collate};
```

`source_type` ∈ `product | product_variation | category | attribute | page | post | document | faq | policy`.

The enum is vocabulary, not capability: `product` and `post` have extractors today (`page` flows through the post extractor), and the remaining values are **reserved for planned extractors** — declared now so adding one is a registration, never a migration.

**`content_hash` is what makes FR-KB-07 work.** On `save_post` / product save, the extractor hashes the extracted text. Unchanged hash means no re-embedding — which matters because a merchant bulk-editing stock would otherwise trigger a full re-embed and a provider bill. This is the single most important cost control in the indexing pipeline.

### 5.2 `scr_knowledge_chunks`

```sql
CREATE TABLE {prefix}scr_knowledge_chunks (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id       BIGINT UNSIGNED NOT NULL,
  chunk_index     INT UNSIGNED    NOT NULL DEFAULT 0,
  content         LONGTEXT        NOT NULL,
  content_tokens  INT UNSIGNED    NOT NULL DEFAULT 0,
  embedding       LONGBLOB        NULL,
  embedding_model VARCHAR(64)     NOT NULL DEFAULT '',
  embedding_dims  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  embedded_at     DATETIME        DEFAULT NULL,
  created_at      DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY source_chunk (source_id, chunk_index),
  KEY embedding_model (embedding_model),
  FULLTEXT KEY content_ft (content)
) {charset_collate};
```

**Embeddings are packed float32 in a `LONGBLOB`, not JSON.** A 1536-dimension vector is 6,144 bytes packed versus roughly 20 KB as a JSON array — a 3× storage difference and a much larger parsing difference on every retrieval.

**`FULLTEXT` on `content` is what makes hybrid retrieval possible** (FR-KB-05). It is also the answer to assumption A3, below.

> **FR-KB-08 is a schema-level rule, not a runtime one.** Price, stock status, and order state are **never** written into `content`. Extractors emit descriptive text only. A chunk that embedded "£24.99, 3 in stock" would keep asserting that after the merchant changed it, and no amount of prompt engineering fixes a stale corpus. Volatile fields are read live at request time and injected into the prompt separately.

---

## 6. Vector Search at Scale — R-TECH-01 / Assumption A3

Assumption A3 was that MySQL cosine similarity over a custom table is fast enough at 10k–50k chunks. Cosine over a 1536-dimension packed vector costs about 90 microseconds in PHP — measured, not guessed — so a full scan runs roughly 91 ms at 1,000 chunks, 2.7 s at 30,000, and 13.6 s at 150,000. **A full scan on every query does not survive A3 at scale — but below a couple of thousand chunks it survives it comfortably**, and the two regimes are not equally accurate. `KnowledgeChunkRepository::search()` is therefore built around that dividing line rather than around one strategy, and every query reports which path answered it. Five outcomes:

- **`dense_full`** — at or below `DENSE_SCAN_THRESHOLD = 2000` embedded chunks, the prefilter is skipped and every vector is scored. This is the accurate path, and it is the default for any realistic small store; 2,000 keeps the scan inside the 300 ms p95 retrieval budget with headroom.
- **`hybrid`** — above the threshold, `MATCH(content) AGAINST(… IN NATURAL LANGUAGE MODE)` narrows the corpus to the top *N* candidates (default 200) over the `content_ft` index, and cosine runs in PHP over only those — 200 × 1536 floats is roughly 1.2 MB of arithmetic.
- **`dense_fallback`** — the lexical arm returned fewer than `LEXICAL_FLOOR = 1` rows, i.e. nothing at all: a query phrased entirely in words absent from the corpus. A bounded full scan runs instead, capped at `MAX_DENSE_SCAN = 5000` and firing `storecrew_retrieval_truncated` when the cap bites, so incomplete recall is announced rather than swallowed. The floor is deliberately 1 — a query matching one or two chunks is *precise*, not failed, and an earlier floor of 3 triggered the very scan the design exists to avoid.
- **`lexical_only`** — no query vector exists (no embedding provider configured); FULLTEXT relevance alone.
- **`empty`** — nothing matched by either arm.

`search()` returns `strategy`, `candidates`, and `truncated` alongside the rows, so callers — and the run record — see which path ran instead of assuming.

**Fusion is configurable, and the measured default is all-dense.** `DEFAULT_DENSE_WEIGHT = 1.0`, filterable via `storecrew_dense_weight`: the lexical arm contributes nothing to *ranking* by default, though it still selects candidates on the hybrid path — candidate selection and scoring are different jobs. The reason is in the normalisation: lexical scores are scaled against the best match *within the candidate set*, so the top keyword hit always scores 1.0 however weak the absolute match, and on a narrow candidate set an incidental word overlap outranks a strong semantic match — which is exactly how "warm hat for winter" once returned a wholesale policy page.

**The measurement FR-KB-09 required has been taken, and it is why this section reads as it does.** On the original ten-question fixture set the lexical-prefilter path scored recall@3 = 0.80 — below the 0.88 bar this document set — against 1.00 for the full dense scan. The current 23-fixture set on a 62-chunk corpus scores 0.96 at weight 1.0, and the weight sweep is monotonic: 0.80 → 0.83, 0.90 → 0.91, 0.95 → 0.91, 1.00 → 0.96. The fallback an earlier draft named first — widen the prefilter — is ruled out, and the reason is structural rather than tunable: FULLTEXT cannot match "warm hat for winter" to a product named "Beanie" because they share no words, so widening the candidate limit cannot help when the correct answer scores zero lexically and is never a candidate at all.

What remains open is precisely the case A3 was about. **Above 2,000 chunks the prefilter runs anyway — a multi-second scan is worse than an imperfect answer — and recall there is unmeasured and expected to be worse.** That is the case that would force the external vector store R-TECH-01 named, which is why nothing outside the retrieval repository knows how vectors are stored.

### Storage sizing

| Chunks | Corpus scale | Embedding bytes (1536-d f32) | Table size, approx |
|---|---|---|---|
| 5,000 | ~1,500 products | 29 MB | 45 MB |
| 30,000 | ~10,000 products | 176 MB | 260 MB |
| 150,000 | ~50,000 products | 879 MB | 1.3 GB |

At 50k products the embedding column alone approaches a gigabyte, which is a real problem on budget shared hosting where the entire database quota may be 1 GB. Two mitigations are available and should be decided at Gate 2: **float16 quantisation** (halves storage, costs ~1% recall) or a **smaller embedding model** (768-d halves it again). A pre-flight estimate must be shown before indexing begins so a merchant is never surprised — the same discipline as the cost estimate in R-COST-01.

---

## 7. Metering

### 7.1 `scr_usage_events`

```sql
CREATE TABLE {prefix}scr_usage_events (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  metric          VARCHAR(32)     NOT NULL,
  quantity        BIGINT UNSIGNED NOT NULL DEFAULT 1,
  period          CHAR(7)         NOT NULL,
  conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  agent_id        VARCHAR(64)     NOT NULL DEFAULT '',
  provider        VARCHAR(32)     NOT NULL DEFAULT '',
  model           VARCHAR(64)     NOT NULL DEFAULT '',
  cost_micros     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  recorded_at     DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY metric_period (metric, period),
  KEY period (period),
  KEY conversation_id (conversation_id),
  KEY recorded_at (recorded_at)
) {charset_collate};
```

`metric` ∈ `conversation | message | agent_run | document_indexed | chunk_embedded | tokens_in | tokens_out`.

**This design deliberately outlives PRD open question 1.** Whether the free tier meters *conversations* or *indexed documents* is still undecided. Rather than block, the table records every metric and the limit is expressed as `(metric, ceiling)` configuration. Changing the meter later is an options change, not a migration — and both figures are already being collected, so the decision can be made from this installation's own data.

`period` is a denormalised `YYYY-MM` string so a monthly rollup is an indexed equality match rather than a date-range scan.

### 7.2 `scr_usage_counters`

```sql
CREATE TABLE {prefix}scr_usage_counters (
  metric      VARCHAR(32)     NOT NULL,
  period      CHAR(7)         NOT NULL,
  total       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cost_micros BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at  DATETIME        NOT NULL,
  PRIMARY KEY  (metric, period)
) {charset_collate};
```

The free-tier limit check (FR-LIC-02) runs on **every** conversation start. Doing that as `COUNT(*)` over `usage_events` would degrade as the table grows — precisely as a store gets busier. Counters are incremented in the same transaction as the event via `INSERT … ON DUPLICATE KEY UPDATE total = total + VALUES(total)`, which is atomic and needs no read-modify-write.

`usage_events` remains the source of truth; counters are a cache and can be rebuilt from it.

---

## 8. Operations

### 8.1 `scr_index_runs`

```sql
CREATE TABLE {prefix}scr_index_runs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          VARCHAR(32)     NOT NULL DEFAULT 'full',
  status        VARCHAR(16)     NOT NULL DEFAULT 'queued',
  total         INT UNSIGNED    NOT NULL DEFAULT 0,
  processed     INT UNSIGNED    NOT NULL DEFAULT 0,
  failed        INT UNSIGNED    NOT NULL DEFAULT 0,
  cursor_position VARCHAR(191)  NOT NULL DEFAULT '',
  cost_micros   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_error    TEXT            NULL,
  heartbeat_at  DATETIME        DEFAULT NULL,
  started_at    DATETIME        NOT NULL,
  finished_at   DATETIME        DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_started (status, started_at)
) {charset_collate};
```

**`cursor_position` and `heartbeat_at` exist because of R-TECH-03.** Budget hosts kill long-running processes without warning, and some run different PHP for web and CLI. `cursor_position` makes a run resumable from where it died rather than restarting (and re-billing) from zero. `heartbeat_at` is how the dashboard distinguishes *running* from *dead but still marked running* — a distinction FR-ADMIN-08 requires, because a job that silently stopped is the failure mode merchants actually hit.

### 8.2 `scr_audit_log`

```sql
CREATE TABLE {prefix}scr_audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_type  VARCHAR(16)     NOT NULL DEFAULT 'system',
  actor_id    VARCHAR(64)     NOT NULL DEFAULT '',
  action      VARCHAR(64)     NOT NULL,
  object_type VARCHAR(32)     NOT NULL DEFAULT '',
  object_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ip_hash     CHAR(64)        NOT NULL DEFAULT '',
  data        LONGTEXT        NULL,
  created_at  DATETIME        NOT NULL,
  PRIMARY KEY  (id),
  KEY action_created (action, created_at),
  KEY actor (actor_type, actor_id),
  KEY object (object_type, object_id)
) {charset_collate};
```

`actor_type` ∈ `user | agent | system`. Every agent write action, every approval, every licence transition, every settings change lands here.

**`ip_hash`, never a raw IP.** Rate limiting and abuse detection need to recognise a repeat visitor, not identify them. A salted hash does that and keeps the table out of GDPR scope as personal data.

### 8.3 `scr_agent_configs`

```sql
CREATE TABLE {prefix}scr_agent_configs (
  agent_id     VARCHAR(64)  NOT NULL,
  enabled      TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  persona      LONGTEXT     NULL,
  guardrails   LONGTEXT     NULL,
  model_policy LONGTEXT     NULL,
  tool_modes   LONGTEXT     NULL,
  version      INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at   DATETIME     NOT NULL,
  PRIMARY KEY  (agent_id)
) {charset_collate};
```

`tool_modes` is the JSON map backing FR-AGENT-05 — `{"coupon.create": "required", "order.note": "auto"}`. **Autonomy is stored per tool, never as a global switch**, because "let it write order notes" and "let it issue coupons" are not the same decision.

`version` increments on every save and is what `agent_runs.prompt_hash` is reconciled against, so a merchant can tell whether an answer predates a persona change.

---

## 9. Migrations

Forward-only, idempotent, versioned (FR-CORE-04).

- Each migration is a class with an integer `version()` and an `up()`. There is no `down()` — a rollback that has to reverse a data transform on a live store is more dangerous than rolling forward.
- The runner compares `storecrew_schema_version` against the highest registered migration and applies the gap in order.
- It hooks `admin_init` unconditionally and gates on the version comparison itself — `Migrator::needs_migration()` against `storecrew_schema_version` — **not during activation**, and not on a flag. A fatal mid-schema during activation leaves a site that cannot retry, and updating by file upload never calls the activation hook at all. **There is no flag at all**: `storecrew_needs_upgrade` was written by the activator and deleted at the end of a successful run, and read by nothing in between (Gate 2). It is gone as of Migration003 — the write from `Activator`, the delete from `Migrator::run()`, and the rows already on disk. A flag set at activation is absent on precisely the FTP-upgrade path that has migrations pending, so it could never have become the trigger; and because the delete sat in `run()`, which only executes when there *is* pending work, re-activating an already-current site left an autoloaded `1` that nothing would ever clear.
- Under WP-CLI the same check also hooks `init` at priority 5, because `admin_init` never fires there — a site administered purely by `wp` would otherwise never get its tables.
- Schema creation uses `dbDelta()`, which imposes real formatting constraints: two spaces after `PRIMARY KEY`, `KEY` not `INDEX`, one field per line, and lowercase types. These are not style preferences; `dbDelta` silently fails to apply changes when they are violated.
- A migration lock (`storecrew_migration_lock`, 5-minute TTL) prevents two concurrent admin requests running the same migration twice.
- Add-ons contribute their own migrations via `storecrew_register_migrations` and own their own version series.

**Four migrations ship; `storecrew_schema_version` is 4.** Migration001 creates every table above. Migration002 adds `agent_runs.cost_known` (§ 4.1) and is the first time this machinery ran in anger rather than in a probe — it worked, which is the only evidence that matters for a mechanism whose failure mode is a half-migrated production store. Migration003 deletes `storecrew_needs_upgrade` and Migration004 deletes `storecrew_version`; they are the first migrations that touch no schema. Both options had to be cleaned up on installs that had already stored them, and neither a WordPress.org update nor an FTP upload re-activates a plugin, so the activator could not do it — the runner is the only upgrade path that fires on every route in. They are two migrations rather than one because 003 had already been applied when 004 was written, and amending an applied migration is the forward-only contract's one forbidden move: every site already at that version silently skips the added work.

**Activation now writes no version of its own.** `storecrew_version` recorded `STORECREW_VERSION` at activation and was read by nothing; every surface that reports a version — `/bootstrap`, the admin page, asset cache-busting — reads the constant, which is compiled into the running code and cannot be stale. The option could be, and on the ordinary upgrade path always was: written only at activation, it went on reporting the version the merchant ran *before* the update until they next toggled the plugin. The upgrade-detection job it looked like it was doing belongs to the migrator, keyed on `storecrew_schema_version` — one-time work follows a schema or state change, not a marketing version bump that may alter nothing.

Migration002 also sets the pattern for column additions: a guarded `ALTER`, not `dbDelta`. `dbDelta` wants the full `CREATE` statement and diffs it, so adding one column means restating forty and inviting every silent-failure mode in the list above for no benefit. A `SHOW COLUMNS` check before and after makes the migration idempotent — which the forward-only contract requires, because a mid-series failure is resumed by re-running, never by rolling back.

---

## 10. Premium Tables

Owned by `storecrew-pro`, created by its own migrations, sharing the `scr_` prefix. Detailed at Gate 4.

| Table | Purpose |
|---|---|
| `scr_segments` | Customer segment definitions and cached membership |
| `scr_campaigns` | Campaign records, targeting, status, attribution |
| `scr_workflows` | Workflow graph definitions |
| `scr_workflow_runs` | Per-execution log with node-level results |

**Premium tables are not dropped when the free plugin is uninstalled**, and vice versa. Each plugin's uninstall removes only what it created (FR-DIST-06).

---

## 11. Retention & Privacy

| Data | Default | Configurable | Status |
|---|---|---|---|
| Conversations and messages | 12 months | Yes, 1–60 months or never (`storecrew_retention`) | **Implemented** — hourly sweep; cascades to messages, runs, tool calls |
| Agent runs and tool calls | 6 months | Yes, 1–60 months | **Implemented** — hourly sweep, batched at 500; **pending approvals are exempt** |
| Usage events | 24 months | Yes — counters retained indefinitely | **Implemented** — events only; counters untouched |
| Audit log | 24 months (730 days) | Yes, minimum 6 months (180-day floor) | **Implemented** — hourly maintenance sweep, batched at 500 |
| Knowledge chunks | Until source deleted or reindexed | n/a | **Implemented** — deletion follows the source |
| Attribution links | With their conversation | Follows the conversation window | **Implemented** — cascades from the conversation prune; no separate window |

All four windows are enforced from the hourly `MaintenanceJob` sweep, each on the pattern the audit pruner set: batched at 500 rather than a single mass `DELETE`, which would lock the table on a busy store — exactly when it is largest. Three deliberate asymmetries, each probe-tested: pruning a conversation **cascades** to its messages, runs, and tool calls whatever their own age, because a run whose conversation is gone is unexplainable, which defeats the reason runs are kept; a **pending approval survives any window**, because an undecided write silently vanishing from the queue would make the approval model a lie; and sub-floor settings clamp *up*, so a mistyped window cannot quietly disable retention the merchant believes is on. The audit floor stays 180 days — audit history is a security control. Attribution links (§ 3.3) have no window of their own and cascade with the conversation instead: a link to a conversation nobody can open is a revenue figure nobody can check, which is the shape of defect this codebase treats as worse than a missing figure. The consequence is stated rather than hidden — attribution history is bounded by conversation retention, so a report over a period older than that window reports less than happened.

- **GDPR export and erasure are wired** (`Core/Privacy/PersonalData`, registered with the WordPress personal-data tools; resolved lazily so the DB-free harness never constructs a repository). Export pages a customer's conversations with full transcripts, excluding system rows — operator notes are about the conversation, not data held on the person. Erasure anonymises exactly as specified: `customer_id`, `identity_verified`, `verified_order_id`, and the session-token binding are stripped (so no surviving cookie can resume the thread), attribution links are **deleted** rather than anonymised — the row holds no personal data but "the person who owns order 4182 had a conversation before buying" is a fact about them, the same kind of link `verified_order_id` is stripped for — and message content is blanked, while rows and aggregate counters survive — they contain no personal data, and removing counters would corrupt billing history. Reach is bounded by the identity model: anonymous conversations carry only a token digest and are not attributable by email, so a privacy request cannot reach them — a property, not a gap, since honouring erasure must not require *creating* a link the system never held.
- **Structured storage never holds a raw email address.** `ToolExecutor` redacts identity-bearing arguments before they are persisted — the `email` key becomes `[redacted]`, and a pattern pass scrubs addresses volunteered inside free-text values — so even a failed `identity.verify` attempt records *that* an email was supplied, never which one. (The tool itself still receives the raw value; redaction happens at the storage boundary, not the execution one. The `storecrew_redacted_argument_keys` filter may only add keys, never remove the shipped ones.) The one place an address can still land is `scr_messages.content`, which stores whatever the customer typed — that is question 4 below, not a gap in this rule.
- **No raw IP address is stored anywhere** — only a salted SHA-256 (§ 8.2).

---

## 12. Open Questions for Gate 2 Review

**Standing recorded at the Gate 2 approval, 2026-08-07:** Q5 was resolved by
Gate 1 (D1 — conversations, 100/month; the schema already meters them). Q2 is
partially answered by the FR-KB-09 measurement and reads accordingly below.
Q1 and Q4 are carried forward as open design questions — neither blocks a
schema that stores width-agnostic vectors and unencrypted text today. Q3 is
settled alongside the retention pruning work now scheduled in 14 § M1.

1. **Embedding precision** — float32, float16, or a 768-dimension model? At 50k products this is the difference between 880 MB and 220 MB, and it changes which hosting tier the product is viable on. Needs a recall measurement to decide honestly.
2. **Is the two-stage retrieval acceptable?** Partially answered by the FR-KB-09 measurement (§ 6): on the small corpus, full-dense wins outright — 0.96–1.00 recall@3 against 0.80 for the prefilter path — so below 2,000 chunks the prefilter is not used at all, and it survives only where a full scan is too slow. What still wants an answer is the large-corpus case the prefilter exists for: recall above 2,000 chunks is unmeasured, and a poor figure there forces the external vector store (R-TECH-01).
3. **Conversation retention default** — 12 months is generous for storage and useful for analytics. A shorter default is safer for privacy and cheaper to host.
4. **Should `scr_messages.content` be encrypted at rest?** It can contain anything a customer typed. Encryption blocks the `FULLTEXT` search that conversation history search would want.
5. **The metering decision from PRD Q1** is now deferrable rather than blocking, but still wants answering before the free tier ships.
