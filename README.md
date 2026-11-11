# hkm-plugin-pgvector

`VectorStorePort` over PostgreSQL's **pgvector** extension, through the
application's existing `DatabasePort`.

Solves `vector.store`. No new daemon: a project that already runs PostgreSQL adds
one extension. Routes: none, on purpose — a similarity search over private text is
a query oracle if it is an endpoint.

```php
// A repository / service that declares  "requires": ["vector.store"]
$store->ensureCollection(new VectorCollection('docs', dimensions: 1536, metric: VectorMetric::Cosine));
$store->ensureIndex(new VectorIndex('docs'));                       // HNSW; m=16, ef_construction=64

$store->upsertMany('docs', [
    new VectorRecord('chunk-17', Vector::of($embedding), ['source_id' => 42, 'lang' => 'fr']),
]);

$hits = $store->search(
    VectorQuery::nearest('docs', Vector::of($queryEmbedding), k: 10)
        ->where(VectorFilter::eq('lang', 'fr'), VectorFilter::gt('source_id', 10)),
);
// list<VectorMatch>: id, distance (LOWER IS CLOSER, every metric), payload
```

The contract, and the reasoning behind every refusal in it, is the docblock on
`VectorStorePort` in the kernel. This README is about THIS adapter: what it does
with PostgreSQL, what was measured, and what was not.

---

## Install

1. The extension files must exist on the PostgreSQL server (`brew install pgvector`,
   `apt install postgresql-NN-pgvector`, a managed service's checkbox, …).
2. **A database administrator runs `CREATE EXTENSION vector;`** in the database. This
   plugin never does: it needs privileges the application should not hold, and the
   first call on a database without it throws a message that says exactly this.
3. The `vector` type must be on the connection's `search_path` (it lives in the schema
   the extension was created in — usually `public`). If it is not, the first
   `CREATE TABLE` fails with `type "vector" does not exist` and the error says why.
4. Enable the plugin; add `"requires": ["vector.store"]` to whatever uses it.

Requires pgvector **0.5.0** or newer (HNSW). 0.8.0 or newer is recommended: iterative
scans, which narrow how often the slow path below is taken, arrived there. Older
versions work and are correct — they just take the exact fallback more often.

## Layout

One table per collection, `hkm_vec_<name>`:

| column | type | |
|---|---|---|
| `id` | `text COLLATE "C"` primary key | bytewise, so the `(distance, id)` tie-break is the same on every cluster |
| `embedding` | `vector(N) NOT NULL` | PostgreSQL itself refuses a wrong-length vector |
| `payload` | `jsonb NOT NULL` | flat scalars only; **key order is not preserved** (jsonb sorts keys) |

Plus a GIN `jsonb_path_ops` index on `payload` (`..._gin`) and, once you call
`ensureIndex()`, an HNSW index (`..._hnsw`). The collection's **metric is stored as a
table comment** (`hkm-vector:{"metric":"cosine"}`) in the same transaction as
`CREATE TABLE` — there is no registry table to drift from the real tables, and no
migration.

Collection names are `[a-z][a-z0-9_]{0,47}`. A table of that name that this plugin did
not create is never adopted and never dropped.

There is **no `tenant_id` and no `deleted_at`**. Isolation is whichever database the
`DatabasePort` is bound to (a per-tenant connection), or a payload field the caller
filters on — the weaker choice, see "The slow path". Vectors are derived data, and a
soft-deleted vector would still be returned by a nearest-neighbour search unless every
query remembered a filter; deletes are hard.

## What was measured

Everything in this section was **run**, on PostgreSQL 18.6 + pgvector 0.8.7 (Homebrew,
macOS arm64), on a scratch cluster, on 2026-11-11. The datasets: a 5-row hand set, and
30,000 uniform-random 64-dimension vectors with `tenant` (50 values, 2% each) and
`rare` (15 rows, 0.05%) payload fields.

### The headline: a filtered HNSW query silently returns too few rows, or none

An HNSW scan finds `hnsw.ef_search` (default 40) candidates and applies the `WHERE`
**afterwards**.

| query (30,000 rows, cosine HNSW) | plan | rows returned |
|---|---|---|
| unfiltered, `LIMIT 10` | `Index Scan using t_cos` | 10 |
| `tenant = 't27'` (2%), `LIMIT 10` | `Index Scan` + `Filter`, "Rows Removed by Filter: 38" | **2** |
| `rare = 1` (0.05%, 15 rows exist), `LIMIT 10` | `Index Scan` + `Filter`, "Rows Removed: 40" | **0** |
| unfiltered, `LIMIT 100`, default `ef_search` | `Index Scan` | **40** |
| same, `SET hnsw.ef_search = 200` | | 100 |

No error, no warning. The planner chose the HNSW index for the 0.05% filter even with
a GIN index on the payload present, because it keeps no statistics for jsonb
containment and cannot know the filter is rare.

`hnsw.iterative_scan` (pgvector 0.8, off by default) helps but is not a complete
answer: `relaxed_order` returned 10 rows at 2%; `strict_order` returned 10 of which 9
were true neighbours; with the planner forced onto the index at 0.05% and
`strict_order`, **8 of the 10 available** came back, because the scan stopped at
`hnsw.max_scan_tuples` (20,000; "Rows Removed by Filter: 19924").

**What this adapter does about it** — the port promises `min(k, matches)`:

1. *Approximate pass.* `hnsw.ef_search` raised to `max(PGVECTOR_EF_SEARCH, k)`,
   iterative scan on.
2. *Exact pass*, only if pass 1 returned fewer than `k` **and** an HNSW index exists:
   `enable_indexscan = off` for the statement, so every matching row is considered. If
   that also returns fewer than `k`, fewer than `k` records match — it is the
   definitive answer.

Both passes run in a transaction that is **always rolled back**, with settings applied
by `set_config(name, value, true)` (transaction-local). Measured: `SET LOCAL` outside a
transaction only warns and does nothing; inside a savepoint it is undone by
`ROLLBACK TO` — so this is correct whether or not the caller already holds a
transaction (the Database plugin turns a nested `beginTransaction()` into a
savepoint). Nothing leaks onto the connection; a test pins it.

### The slow path — what the fallback costs

Through this adapter, 30,000 × 64-dim cosine collection, HNSW m=16 / ef_construction=64
(index build: **10.7 s**), warm, local socket:

| search | pass-1 plan (`explainSearch()`) | time | `exact()` |
|---|---|---|---|
| unfiltered, k=10 | `Index Scan using …_hnsw` | 3.7 ms | 19.2 ms |
| unfiltered, k=100 | `Index Scan using …_hnsw` | 3.4 ms | 19.7 ms |
| `tenant = 't27'` (2%), k=10 | `Sort` over a scan (planner went exact on its own, 10 rows) | 4.1 ms | 3.5 ms |
| `rare = 1` (0.05%), k=10 | `Index Scan using …_hnsw` | 43.2 ms | **8.7 ms** |
| `n` between 1000 and 1100, k=10 | `Sort` over a scan | 20.9 ms | 17.9 ms |

Read the fourth row: for a highly selective **equality** filter the GIN index makes the
exact query faster (8.7 ms) than the HNSW path that then has to be repaired (43 ms).
This adapter does not yet choose between them; it takes the index first. If one of your
filters is that selective and hot, call `->exact()` for it, or — better — make it a
collection per value. Ranges cannot use the GIN index (`jsonb_path_ops` serves `@>`
only), so a selective range on a big collection is a full scan. These are the numbers
of ONE synthetic dataset on ONE machine; the *shape* is the finding, not the digits.

`explainSearch(VectorQuery, exact: bool)` returns the plan lines — it is how you answer
"why is this search slow".

### Other measured divergences

- **Distance is not what you expect for dot product.** `<#>` is the *negative* inner
  product: `[2,0,0]·[1,0,0]` → `-2`, orthogonal → `-0`. The port keeps pgvector's sign
  (lower is closer) and this adapter normalises `-0` to `0`.
- **Ties are real.** Cosine distance of `[1,0,0]` and `[2,0,0]` from `[1,0,0]` is `0`
  for both. With `ORDER BY d, id` PostgreSQL 18 plans an `Incremental Sort` *over the
  same `Index Scan`* (it read 11 rows for k=10), so the tie-break costs almost nothing.
  `id` is `COLLATE "C"` because `ORDER BY id` on this server gave `a,b,B` under the
  default collation and `B,a,b` bytewise.
- **The operator must match the index.** Cosine index + `<->` → `Seq Scan` over all
  30,000 rows, no error. The adapter derives the operator from the collection's metric.
- **Zero vectors.** `[0,0,0] <=> [1,0,0]` is `NaN`; a zero *query* makes every distance
  `NaN`; NaN sorts after every real distance. A stored zero vector is silently the
  farthest thing from everything. Refused for cosine collections by the port.
- **Dimension mismatch.** Insert: `expected 3 dimensions, not 2`; query: `different
  vector dimensions 3 and 2`; both SQLSTATE `22000`. Checked by the port first, with the
  collection name in the message; the database is the backstop.
- **Limits.** HNSW refuses a column over **2000** dimensions ("column cannot have more
  than 2000 dimensions for hnsw index", measured at 2001 and 3000) though the column
  type goes to 16000; `m` 2..100; `ef_construction` 4..1000 **and ≥ 2·m** (m=16 with
  ef_construction=8 is refused though 8 is in range); `hnsw.ef_search` 1..1000. These
  are where `Vector::MAX_DIMENSIONS`, `VectorIndex`'s checks and `VectorQuery::MAX_K`
  come from.
- **`CREATE INDEX IF NOT EXISTS` swallows a different definition** (`NOTICE: relation
  … already exists, skipping`; the old operator class stays). The same definition under a
  new name creates a *second* index (three on the table, counted). So `ensureIndex()`
  reads the catalog and answers `AlreadyExists` or `Conflict`, never both.
- **Float32.** `0.123456789012` reads back as `0.12345679`.
- **Nulls and NaN.** A nullable vector column accepts NULL (the column here is
  `NOT NULL`); `[NaN,1,1]` and `[Infinity,1,1]` are refused by the type.
- **jsonb in filters.** Containment (`@>`) is type-strict — `"5"` does not contain `5`,
  `true` does not contain `1`, `5` does contain `5.0` — and is the only operator the GIN
  index serves. `(payload->>'k')::numeric > 5` **throws** for the whole query if any row
  has non-numeric text in `k`; `payload->'k' > '5'::jsonb` never throws but orders by
  type first, so `true` is *greater than 5*. Ranges here are
  `jsonb_typeof(…) = 'number' AND … > …` with jsonb on both sides.
- **"Not paid" has three answers in one database** over `{open}`, `{paid}`, `{}` and
  `{status:null}`: `<>` on text → 1 row, `<>` on jsonb → 2, `IS DISTINCT FROM` → 3.
  Which is why the port has no negation.
- **jsonb cannot hold `U+0000`** (`unsupported Unicode escape sequence`); refused at the
  port with the field name.

## Configuration

| key | default | |
|---|---|---|
| `PGVECTOR_EF_SEARCH` | `64` | floor for `hnsw.ef_search`; the effective value is `max(this, k)` |
| `PGVECTOR_ITERATIVE_SCAN` | `relaxed_order` | `off`, `relaxed_order` or `strict_order` |
| `PGVECTOR_MAX_SCAN_TUPLES` | `20000` | where an iterative scan gives up |

None can break `min(k, matches)`: a short pass is repeated exactly. They change how
often the second pass is needed. The connection itself is the Database plugin's.

## CLI

`hkm cli vector:status` — the extension version; every collection with its dimension,
metric, row count and index definition. **`index: NONE — every search is a full scan`**
is the line to look for: nothing errors without an index, it is just slow once the table
is big. `--require-index` exits non-zero if any collection lacks one (a deploy gate).
Read-only; prints no host, DSN or credential.

## Operating notes

- **`ensureIndex()` takes a `SHARE` lock**: reads continue, writes wait for the build
  (10.7 s at 30,000 × 64). `CONCURRENTLY` cannot run inside a transaction and this method
  must work whether or not the caller holds one. For a large live table, build the index
  out of band and let `ensureIndex()` confirm it (`AlreadyExists`).
- **Changing parameters or metric is a rebuild**, and is explicit: `dropIndex()` then
  `ensureIndex()`. A different embedding model is a new collection and a re-embed.
- **`upsertMany`** is atomic (one transaction), at most 1000 records, no duplicate ids; it
  issues one `upsert()` per row, i.e. 1000 round trips at the limit.
- The status command and `explainSearch()` are the only places the plan is visible.

## Tests

```
vendor/bin/phpunit --testsuite Unit            # no database needed
PGVECTOR_TEST_PORT=5432 PGVECTOR_TEST_DB=scratch PGVECTOR_TEST_USER=me \
  PGVECTOR_TEST_NOEXT_DB=scratch_noext vendor/bin/phpunit --testsuite Integration
```

Integration **skips, loudly, and runs nothing** without `PGVECTOR_TEST_PORT`. It needs a
database that has the extension created; `PGVECTOR_TEST_NOEXT_DB` (optional) names one
that does not, for the "extension missing" path. It uses the real Database-plugin
adapter from a sibling checkout (`../hkm-plugin-database`). Each test creates uniquely
named collections and drops exactly those, never by pattern. **Use a scratch database.**

## Verified / NOT verified

**Verified** (run, against PostgreSQL 18.6 + pgvector 0.8.7):
- every measurement above; the store's behaviour end to end over the real
  `MultiDriverDatabaseAdapter` — collection / index lifecycle including both conflict
  kinds and the foreign-table refusal, upsert/replace/get/delete, atomic `upsertMany` with
  a mid-batch failure, all three metrics against distances computed in Python, tie order,
  every filter against hand-written expectations *and* `VectorFilter::matches()`,
  `min(k, matches)` against brute force in PHP with the failing naive query asserted as a
  precondition, `k` above `ef_search`, the planner using the HNSW index per metric and not
  under `exact()`, settings not leaking (top level and inside a caller's transaction), the
  `vector:status` command executed.

**NOT verified:**
- **Any PostgreSQL other than 18.6, any pgvector other than 0.8.7.** In particular
  0.5–0.7 (no iterative scan) were not run.
- **Scale.** 30,000 rows was the largest. HNSW memory, build time (`maintenance_work_mem`
  warnings), and the cost of the exact fallback on millions of rows were not measured.
- **Clustered data.** The datasets are uniform random; real embeddings cluster and HNSW
  recall and the planner's choices differ. Recall was only checked as a loose floor
  (≥ 0.8 at 1,500 vectors).
- **Concurrent writers**, a search racing an `ensureIndex()`, and HNSW behaviour after
  heavy deletes (dead entries wait for `VACUUM`).
- **Managed PostgreSQL services** (RDS, Cloud SQL, Neon, Supabase), connection poolers in
  transaction mode (the `set_config(…, true)` scheme is transaction-local so it should
  hold, but was not run through one), and `search_path` configurations other than the
  default.
- **Anything about another vector store.** The port's claims about "other stores" are the
  author's recollection of their documentation; none was run.
- The Database plugin's `PooledDatabaseAdapter` was not used; only `MultiDriverDatabaseAdapter`.
- Windows. The real `vector:status` was run through a unit-style harness, not an installed
  `hkm` launcher.
