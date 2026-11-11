# Changelog

All notable changes to `hkm-plugin-pgvector` are documented here. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **First release: `VectorStorePort` over pgvector, through `DatabasePort`.** Solves
  `vector.store`. One table per collection with a `vector(N)` column and a jsonb
  payload, an HNSW index on request, and a GIN index on the payload. No daemon, no
  migration (the collection's metric is a table comment), no routes.
- **A search returns `min(k, matches)`.** An HNSW scan applies its filter after a fixed
  candidate list, so a filtered query returned 2 rows of 10 at 2% selectivity and 0 at
  0.05% — measured, with no error. The store runs an approximate pass with
  `hnsw.ef_search` raised to at least `k` and iterative scan on, and repeats exactly when
  that comes up short and an index exists. Settings are transaction-local and always
  rolled back, so they never outlive the statement or leak into a caller's transaction.
- **`ensureCollection()` / `ensureIndex()` distinguish `AlreadyExists` from `Conflict`**
  by reading the catalog, because `CREATE INDEX IF NOT EXISTS` leaves a different
  definition in place with only a NOTICE.
- **`explainSearch()`** returns the plan a search would use, and **`vector:status`**
  prints the extension version, every collection and its index (`--require-index` exits
  non-zero for a collection without one).
- Tests: a unit suite that needs no database, and an integration suite that runs against
  a real PostgreSQL with the extension and **skips with a message** when none is
  configured.
