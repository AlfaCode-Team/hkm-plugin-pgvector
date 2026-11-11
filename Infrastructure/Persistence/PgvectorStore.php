<?php

declare(strict_types=1);

namespace Plugins\Pgvector\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DriverAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorCollection;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMatch;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMetric;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorQuery;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorRecord;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorStoreCapability;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorStoreException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\VectorStorePort;
use Plugins\Pgvector\Infrastructure\Config\PgvectorConfig;

/**
 * VectorStorePort over PostgreSQL + pgvector, through DatabasePort ONLY.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * LAYOUT
 * ════════════════════════════════════════════════════════════════════════════
 * One table per collection, `hkm_vec_<name>`:
 *
 *   id        text COLLATE "C" PRIMARY KEY
 *   embedding vector(N) NOT NULL
 *   payload   jsonb NOT NULL DEFAULT '{}'
 *
 * Why a table per collection and not one shared table: an HNSW index needs a
 * column of a FIXED dimension, so a shared table would mean an expression index
 * per collection over a cast, and a dimension mismatch would be caught by the
 * cast rather than by the column type. A table per collection lets PostgreSQL
 * itself refuse a wrong-length vector (measured: "expected 3 dimensions, not
 * 2") as a backstop behind the port-level check.
 *
 * The collection's METRIC is stored as a table comment, `hkm-vector:{"metric":
 * "cosine"}`, in the same transaction as CREATE TABLE. A registry table would
 * need a migration and could drift from the real tables (dropped by hand, the
 * registry still says it exists); a comment cannot.
 *
 * `id` is `COLLATE "C"`. The port orders ties by id, and under the database's
 * default collation that order depends on the locale the cluster was created
 * with ('B' sorts before 'a' bytewise and after it under en_US). A tie-break
 * that differs between two deployments of the same code is not a tie-break.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THE SEARCH, AND WHY IT RUNS UP TO TWO PASSES
 * ════════════════════════════════════════════════════════════════════════════
 * See the port's convergence 1 for the measurements. The short form: an HNSW
 * scan applies the filter after a fixed-size candidate list, so a filtered query
 * can return fewer rows than match — or none — with no error, and the planner
 * does not know when that will happen.
 *
 *   pass 1 (approximate): hnsw.ef_search raised to at least k, iterative scan on.
 *   pass 2 (exact), ONLY when pass 1 returned fewer than k AND an HNSW index
 *          exists: the index is made unusable for this statement, so every
 *          matching row is considered. If it too returns fewer than k, fewer than
 *          k records match.
 *
 * Both run inside a transaction that is ALWAYS rolled back, and the settings are
 * applied with `set_config(name, value, true)` — transaction-local. Measured:
 * `SET LOCAL` outside a transaction does nothing but warn, and inside a
 * SAVEPOINT it is undone by ROLLBACK TO, so this is correct whether or not the
 * caller already holds a transaction (the Database plugin turns a nested
 * beginTransaction() into a savepoint). A session-level SET would leak into the
 * caller's next query on the same connection.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * NO tenant_id, NO deleted_at
 * ════════════════════════════════════════════════════════════════════════════
 * The repository rule says to scope both; this is infrastructure behind a port
 * with no tenant argument, and the port documents where isolation comes from. A
 * vector is DERIVED data: a soft-deleted vector would still be returned by a
 * nearest-neighbour search unless every query remembered a filter, and one that
 * did not would resurrect deleted content. Deletes are hard.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * NOT VERIFIED
 * ════════════════════════════════════════════════════════════════════════════
 * See the README. In particular: concurrent writers, tables beyond ~30,000
 * rows, and any PostgreSQL other than 18.6 with pgvector 0.8.7.
 */
final class PgvectorStore implements VectorStorePort
{
    public const TABLE_PREFIX = 'hkm_vec_';

    private const COMMENT_PREFIX = 'hkm-vector:';

    /** The oldest pgvector with HNSW (and the `hnsw.ef_search` setting). */
    private const MIN_VERSION = '0.5.0';

    private bool $ready = false;

    private ?string $extensionVersion = null;

    /** @var array<string, VectorCollection> */
    private array $known = [];

    public function __construct(
        private readonly DatabasePort $db,
        private readonly PgvectorConfig $config,
    ) {}

    // ── Collections ─────────────────────────────────────────────────────────

    public function ensureCollection(VectorCollection $collection): VectorResult
    {
        $this->assertReady('ensureCollection');

        $existing = $this->describeTable($collection->name, 'ensureCollection');

        if ($existing !== null) {
            return $this->compareCollection($collection, $existing);
        }

        $table = $this->quote(self::table($collection->name));

        try {
            $this->db->beginTransaction();

            try {
                $this->db->execute(
                    "CREATE TABLE {$table} (id text COLLATE \"C\" PRIMARY KEY, "
                    . "embedding vector({$collection->dimensions}) NOT NULL, "
                    . "payload jsonb NOT NULL DEFAULT '{}'::jsonb)",
                );
                // The metric value comes from a closed enum, never from the caller's input.
                $this->db->execute(
                    "COMMENT ON TABLE {$table} IS '" . self::COMMENT_PREFIX
                    . json_encode(['metric' => $collection->metric->value], JSON_THROW_ON_ERROR) . "'",
                );
                // jsonb_path_ops serves `@>` only — which is all Eq/In compile to — and is
                // far smaller than the default jsonb_ops. Measured to turn a 2%-selective
                // filter from a wrong answer into an exact bitmap scan.
                $this->db->execute(
                    'CREATE INDEX ' . $this->quote(self::table($collection->name) . '_gin')
                    . " ON {$table} USING gin (payload jsonb_path_ops)",
                );
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
        } catch (\Throwable $e) {
            // Two creators racing: the loser's CREATE TABLE fails. If what now exists is
            // exactly what was asked for, that is success, not an error.
            $now = $this->tryDescribe($collection->name);

            if ($now !== null) {
                return $this->compareCollection($collection, $now);
            }

            throw $this->fail('ensureCollection', $e);
        }

        $this->known[$collection->name] = $collection;

        return VectorResult::created($collection->name);
    }

    public function collection(string $name): ?VectorCollection
    {
        VectorCollection::assertValidName($name);

        if (isset($this->known[$name])) {
            return $this->known[$name];
        }

        $this->assertReady('collection');

        return $this->describeTable($name, 'collection');
    }

    public function dropCollection(string $name): bool
    {
        VectorCollection::assertValidName($name);
        $this->assertReady('dropCollection');

        // Never drop a table this plugin did not create, however it is named.
        if ($this->describeTable($name, 'dropCollection') === null) {
            return false;
        }

        try {
            $this->db->execute('DROP TABLE ' . $this->quote(self::table($name)));
        } catch (\Throwable $e) {
            throw $this->fail('dropCollection', $e);
        }

        unset($this->known[$name]);

        return true;
    }

    public function ensureIndex(VectorIndex $index): VectorResult
    {
        $this->assertReady('ensureIndex');
        $collection = $this->requireCollection($index->collection, 'ensureIndex');
        $name       = self::table($index->collection) . '_hnsw';
        $opclass    = self::opclass($collection->metric);

        $existing = $this->approximateIndexes($index->collection, 'ensureIndex');

        if ($existing !== []) {
            return $this->compareIndex($index, $opclass, $existing[0]);
        }

        try {
            // A plain CREATE INDEX takes a SHARE lock: reads continue, writes wait until it
            // finishes. CONCURRENTLY would avoid that but cannot run inside a transaction,
            // and this method must work whether or not the caller holds one.
            $this->db->execute(\sprintf(
                'CREATE INDEX %s ON %s USING hnsw (embedding %s) WITH (m = %d, ef_construction = %d)',
                $this->quote($name),
                $this->quote(self::table($index->collection)),
                $opclass,
                $index->m,
                $index->efConstruction,
            ));
        } catch (\Throwable $e) {
            $now = $this->approximateIndexes($index->collection, 'ensureIndex');

            if ($now !== []) {
                return $this->compareIndex($index, $opclass, $now[0]);
            }

            throw $this->fail('ensureIndex', $e);
        }

        return VectorResult::created($name);
    }

    public function dropIndex(string $collection): bool
    {
        VectorCollection::assertValidName($collection);
        $this->assertReady('dropIndex');
        $this->requireCollection($collection, 'dropIndex');

        $dropped = false;

        foreach ($this->approximateIndexes($collection, 'dropIndex') as $index) {
            try {
                $this->db->execute('DROP INDEX ' . $this->quote($index['name']));
            } catch (\Throwable $e) {
                throw $this->fail('dropIndex', $e);
            }

            $dropped = true;
        }

        return $dropped;
    }

    // ── Writing ─────────────────────────────────────────────────────────────

    public function upsert(string $collection, VectorRecord $record): void
    {
        $this->assertReady('upsert');
        $this->requireCollection($collection, 'upsert')->assertAccepts($record->vector);

        try {
            $this->write($collection, $record);
        } catch (\Throwable $e) {
            throw $this->fail('upsert', $e);
        }
    }

    public function upsertMany(string $collection, array $records): int
    {
        if ($records === []) {
            return 0;
        }

        if (!array_is_list($records)) {
            throw new \InvalidArgumentException('upsertMany() takes a list of records.');
        }

        if (\count($records) > self::MAX_BATCH) {
            throw new \InvalidArgumentException(\sprintf(
                'upsertMany() takes at most %d records; %d were given.',
                self::MAX_BATCH,
                \count($records),
            ));
        }

        // Everything that can be refused is refused BEFORE the first write.
        $seen = [];

        foreach ($records as $record) {
            if (!$record instanceof VectorRecord) {
                throw new \InvalidArgumentException('upsertMany() takes VectorRecord instances only.');
            }

            if (isset($seen[$record->id])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Record id "%s" appears twice in one batch. Which version wins differs between '
                    . 'stores, so the batch is refused rather than guessed at.',
                    $record->id,
                ));
            }

            $seen[$record->id] = true;
        }

        $this->assertReady('upsertMany');
        $definition = $this->requireCollection($collection, 'upsertMany');

        foreach ($records as $record) {
            $definition->assertAccepts($record->vector);
        }

        try {
            $this->db->beginTransaction();

            try {
                foreach ($records as $record) {
                    $this->write($collection, $record);
                }

                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollback();
                throw $e;
            }
        } catch (\Throwable $e) {
            throw $this->fail('upsertMany', $e);
        }

        return \count($records);
    }

    public function delete(string $collection, string $id): bool
    {
        VectorRecord::assertId($id);
        $this->assertReady('delete');
        $this->requireCollection($collection, 'delete');

        try {
            return $this->db->execute('DELETE FROM ' . $this->quote(self::table($collection)) . ' WHERE id = :id', ['id' => $id]) > 0;
        } catch (\Throwable $e) {
            throw $this->fail('delete', $e);
        }
    }

    public function deleteWhere(string $collection, array $filters): int
    {
        if ($filters === []) {
            throw new \InvalidArgumentException(
                'deleteWhere() needs at least one filter. An empty list would delete every record; '
                . 'use dropCollection() if that is what is meant.',
            );
        }

        self::assertFilters($filters);
        $this->assertReady('deleteWhere');
        $this->requireCollection($collection, 'deleteWhere');

        $f = FilterSql::compile($filters);

        try {
            return $this->db->execute(
                'DELETE FROM ' . $this->quote(self::table($collection)) . ' WHERE ' . $f['sql'],
                $f['params'],
            );
        } catch (\Throwable $e) {
            throw $this->fail('deleteWhere', $e);
        }
    }

    // ── Reading ─────────────────────────────────────────────────────────────

    public function get(string $collection, string $id): ?VectorRecord
    {
        VectorRecord::assertId($id);
        $this->assertReady('get');
        $this->requireCollection($collection, 'get');

        try {
            $row = $this->db->queryOne(
                'SELECT id, CAST(embedding AS text) AS embedding, CAST(payload AS text) AS payload FROM '
                . $this->quote(self::table($collection)) . ' WHERE id = :id',
                ['id' => $id],
            );

            if ($row === null) {
                return null;
            }

            return new VectorRecord(
                (string) $row['id'],
                Vector::of(self::decode((string) $row['embedding'])),
                self::decode((string) $row['payload']),
            );
        } catch (\Throwable $e) {
            throw $this->fail('get', $e);
        }
    }

    public function count(string $collection, array $filters = []): int
    {
        self::assertFilters($filters);
        $this->assertReady('count');
        $this->requireCollection($collection, 'count');

        $f     = FilterSql::compile($filters);
        $where = $f['sql'] === '' ? '' : ' WHERE ' . $f['sql'];

        try {
            $row = $this->db->queryOne('SELECT count(*) AS n FROM ' . $this->quote(self::table($collection)) . $where, $f['params']);
        } catch (\Throwable $e) {
            throw $this->fail('count', $e);
        }

        return (int) ($row['n'] ?? 0);
    }

    public function search(VectorQuery $query): array
    {
        $this->assertReady('search');
        $definition = $this->requireCollection($query->collection, 'search');
        $definition->assertAccepts($query->vector);

        try {
            if (!$query->exact) {
                $rows = $this->runSearch($definition, $query, exact: false);

                if (\count($rows) >= $query->k) {
                    return $this->matches($rows);
                }

                // Short. Either fewer than k records match, or the index gave up — and the
                // index cannot tell us which. Without an index pass 1 was already exact.
                //
                // THIS GUARD IS PERFORMANCE-ONLY: removing it changes no result (the exact pass
                // would return the same rows), which is why a revert check found it unnoticed until
                // a test counted statements. See PgvectorStoreGuardsTest.
                if (!$this->hasHnswIndex($query->collection)) {
                    return $this->matches($rows);
                }
            }

            return $this->matches($this->runSearch($definition, $query, exact: true));
        } catch (VectorStoreException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw $this->fail('search', $e);
        }
    }

    public function supports(VectorStoreCapability $capability): bool
    {
        return match ($capability) {
            VectorStoreCapability::ExactSearch => true,
        };
    }

    public function describe(): string
    {
        // No host, DSN, user or password: the DatabasePort does not expose them and this
        // must never be the place they leak from.
        return 'pgvector' . ($this->extensionVersion !== null ? ' ' . $this->extensionVersion : '')
            . ' (PostgreSQL, over DatabasePort)';
    }

    /** The extension version once connected, for the status command. */
    public function extensionVersion(): ?string
    {
        $this->assertReady('status');

        return $this->extensionVersion;
    }

    /**
     * Every collection this plugin created, for the status command.
     *
     * @return list<VectorCollection>
     */
    public function collections(): array
    {
        $this->assertReady('collections');

        try {
            $rows = $this->db->query(
                "SELECT c.relname AS name FROM pg_class c WHERE c.relkind = 'r' "
                . "AND c.relname LIKE 'hkm\\_vec\\_%' "
                . 'AND pg_table_is_visible(c.oid) ORDER BY c.relname',
            );
        } catch (\Throwable $e) {
            throw $this->fail('collections', $e);
        }

        $out = [];

        foreach ($rows as $row) {
            $name = substr((string) $row['name'], \strlen(self::TABLE_PREFIX));

            if (preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $name) !== 1) {
                continue;
            }

            $found = $this->describeTable($name, 'collections');

            if ($found !== null) {
                $out[] = $found;
            }
        }

        return $out;
    }

    /**
     * What the catalog says about a collection's indexes, for the status command.
     *
     * @return list<array{name: string, definition: string}>
     */
    public function indexDefinitions(string $collection): array
    {
        $this->assertReady('status');

        return array_map(
            static fn (array $i): array => ['name' => $i['name'], 'definition' => $i['def']],
            $this->approximateIndexes($collection, 'status'),
        );
    }

    // ── Internals ───────────────────────────────────────────────────────────

    public static function table(string $collection): string
    {
        return self::TABLE_PREFIX . $collection;
    }

    private function write(string $collection, VectorRecord $record): void
    {
        $this->db->upsert(
            self::table($collection),
            [
                'id'        => $record->id,
                'embedding' => json_encode($record->vector->toArray(), JSON_THROW_ON_ERROR),
                // FORCE_OBJECT: an empty PHP array would otherwise encode as `[]`, a jsonb ARRAY,
                // and `payload @> {...}` is false for every filter against it.
                'payload'   => json_encode($record->payload, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT | JSON_PRESERVE_ZERO_FRACTION),
            ],
            ['id'],
        );
    }

    /**
     * The plan PostgreSQL would use for a search pass, one line per row.
     *
     * For answering "why is this search slow?" — the plan names `Index Scan using
     * <table>_hnsw` or `Seq Scan`, and a filtered query that silently went the
     * second way is the most common surprise. Read-only: runs EXPLAIN without
     * ANALYZE inside a transaction that is rolled back, with the same settings the
     * real pass uses.
     *
     * @return list<string>
     */
    public function explainSearch(VectorQuery $query, bool $exact = false): array
    {
        $this->assertReady('explainSearch');
        $definition = $this->requireCollection($query->collection, 'explainSearch');
        $definition->assertAccepts($query->vector);

        $plan = $this->buildSearch($definition, $query, $exact);

        return array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $this->inRolledBackTransaction($plan['settings'], 'EXPLAIN (COSTS OFF) ' . $plan['sql'], $plan['params']),
        );
    }

    /**
     * One search pass. Always inside a transaction that is rolled back, so the
     * transaction-local settings cannot outlive it whatever the caller holds open.
     *
     * @return list<array<string, mixed>>
     */
    private function runSearch(VectorCollection $collection, VectorQuery $query, bool $exact): array
    {
        $plan = $this->buildSearch($collection, $query, $exact);

        return $this->inRolledBackTransaction($plan['settings'], $plan['sql'], $plan['params']);
    }

    /**
     * @return array{sql: string, params: array<string, mixed>, settings: array<string, string|int>}
     */
    private function buildSearch(VectorCollection $collection, VectorQuery $query, bool $exact): array
    {
        $operator = match ($collection->metric) {
            VectorMetric::Cosine     => '<=>',
            VectorMetric::Euclidean  => '<->',
            VectorMetric::DotProduct => '<#>',
        };

        $f       = FilterSql::compile($query->filters);
        $where   = $f['sql'] === '' ? '' : ' WHERE ' . $f['sql'];
        $payload = $query->includePayload ? 'CAST(payload AS text)' : "'{}'";

        // ORDER BY the alias rather than a second copy of the expression, so the select list and
        // the sort are one expression and the query vector is sent once. Measured: PostgreSQL
        // still plans `Index Scan using …_hnsw` with ORDER BY on the alias.
        $sql = "SELECT id, d, payload FROM (SELECT id, {$payload} AS payload, "
            . "embedding {$operator} CAST(:q AS vector) AS d FROM " . $this->quote(self::table($query->collection))
            . "{$where} ORDER BY d LIMIT {$query->k}) s ORDER BY d, id";

        $settings = $exact
            // State the whole planner configuration the exact pass depends on instead of inheriting
            // the session's: a session started with enable_seqscan = off (people do, to force index
            // use) would leave the planner choosing among three DISABLED paths — and it could pick
            // the HNSW scan again, which is the very thing this pass exists to avoid.
            ? ['enable_indexscan' => 'off', 'enable_seqscan' => 'on', 'enable_bitmapscan' => 'on']
            : [
                // ef_search caps what an index scan can return: at the default of 40,
                // LIMIT 100 returned 40 rows (measured). The exact fallback would repair that
                // too, so a RESULT test cannot see this line; what it buys is not paying for the
                // second pass, and test_k_above_ef_search_still_returns_k pins it by counting passes.
                'hnsw.ef_search'       => max($this->config->efSearch, $query->k),
                'hnsw.iterative_scan'  => $this->config->iterativeScan,
                'hnsw.max_scan_tuples' => $this->config->maxScanTuples,
            ];

        return [
            'sql'      => $sql,
            'params'   => ['q' => json_encode($query->vector->toArray(), JSON_THROW_ON_ERROR)] + $f['params'],
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string, string|int> $settings
     * @param array<string, mixed>      $params
     * @return list<array<string, mixed>>
     */
    private function inRolledBackTransaction(array $settings, string $sql, array $params): array
    {
        $this->db->beginTransaction();

        try {
            foreach ($settings as $name => $value) {
                $this->db->query('SELECT set_config(:n, :v, true)', ['n' => $name, 'v' => (string) $value]);
            }

            $rows = $this->db->query($sql, $params);
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        $this->db->rollback();

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<VectorMatch>
     */
    private function matches(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            // + 0.0 turns -0.0 into 0.0: `<#>` is the NEGATIVE inner product, so orthogonal
            // vectors come back as "-0" (measured), which is equal to 0 but prints as -0.
            $out[] = new VectorMatch(
                (string) $row['id'],
                (float) $row['d'] + 0.0,
                self::decode((string) $row['payload']),
            );
        }

        return $out;
    }

    private function hasHnswIndex(string $collection): bool
    {
        return $this->approximateIndexes($collection, 'search') !== [];
    }

    /**
     * @return list<array{name: string, def: string, am: string}>
     */
    private function approximateIndexes(string $collection, string $operation): array
    {
        try {
            /** @var list<array{name: string, def: string, am: string}> $rows */
            $rows = $this->db->query(
                'SELECT ic.relname AS name, pg_get_indexdef(i.indexrelid) AS def, am.amname AS am '
                . 'FROM pg_index i JOIN pg_class ic ON ic.oid = i.indexrelid JOIN pg_am am ON am.oid = ic.relam '
                . "WHERE i.indrelid = to_regclass(CAST(:t AS text)) AND am.amname IN ('hnsw', 'ivfflat') ORDER BY ic.relname",
                ['t' => $this->quote(self::table($collection))],
            );
        } catch (\Throwable $e) {
            throw $this->fail($operation, $e);
        }

        return array_values($rows);
    }

    /** @param array{name: string, def: string, am: string} $existing */
    private function compareIndex(VectorIndex $wanted, string $opclass, array $existing): VectorResult
    {
        if ($existing['am'] !== 'hnsw') {
            return VectorResult::conflict($existing['name'], \sprintf(
                'an %s index already exists; this port builds HNSW. Drop it with dropIndex() first.',
                $existing['am'],
            ));
        }

        $actualOpclass = preg_match('/\(embedding (\w+)\)/', $existing['def'], $mo) === 1 ? $mo[1] : '?';
        $actualM       = preg_match("/\\bm='?(\\d+)/", $existing['def'], $mm) === 1 ? (int) $mm[1] : VectorIndex::DEFAULT_M;
        $actualEf      = preg_match("/ef_construction='?(\\d+)/", $existing['def'], $me) === 1
            ? (int) $me[1]
            : VectorIndex::DEFAULT_EF_CONSTRUCTION;

        $differences = [];

        if ($actualOpclass !== $opclass) {
            $differences[] = "operator class is {$actualOpclass}, collection needs {$opclass}";
        }

        if ($actualM !== $wanted->m) {
            $differences[] = "m is {$actualM}, wanted {$wanted->m}";
        }

        if ($actualEf !== $wanted->efConstruction) {
            $differences[] = "ef_construction is {$actualEf}, wanted {$wanted->efConstruction}";
        }

        if ($differences === []) {
            return VectorResult::alreadyExists($existing['name']);
        }

        return VectorResult::conflict(
            $existing['name'],
            implode('; ', $differences) . '. Nothing was changed; dropIndex() then ensureIndex() to rebuild.',
        );
    }

    private function compareCollection(VectorCollection $wanted, VectorCollection $existing): VectorResult
    {
        if ($wanted->equals($existing)) {
            $this->known[$wanted->name] = $existing;

            return VectorResult::alreadyExists($wanted->name);
        }

        $differences = [];

        if ($wanted->dimensions !== $existing->dimensions) {
            $differences[] = "dimension is {$existing->dimensions}, wanted {$wanted->dimensions}";
        }

        if ($wanted->metric !== $existing->metric) {
            $differences[] = "metric is {$existing->metric->value}, wanted {$wanted->metric->value}";
        }

        return VectorResult::conflict(
            $wanted->name,
            implode('; ', $differences) . '. Nothing was changed. A different embedding model needs a NEW collection and a re-embed.',
        );
    }

    /**
     * The collection as the database holds it, or null when there is none.
     *
     * @throws VectorStoreException a table of that name exists but was not created by this plugin
     */
    private function describeTable(string $name, string $operation): ?VectorCollection
    {
        $table = self::table($name);

        try {
            $row = $this->db->queryOne(
                'SELECT to_regclass(CAST(:t AS text)) IS NOT NULL AS present, '
                . 'obj_description(to_regclass(CAST(:t2 AS text)), \'pg_class\') AS comment, '
                . '(SELECT format_type(a.atttypid, a.atttypmod) FROM pg_attribute a '
                . "WHERE a.attrelid = to_regclass(CAST(:t3 AS text)) AND a.attname = 'embedding' AND NOT a.attisdropped) AS ftype",
                ['t' => $this->quote($table), 't2' => $this->quote($table), 't3' => $this->quote($table)],
            );
        } catch (\Throwable $e) {
            throw $this->fail($operation, $e);
        }

        if ($row === null || !self::truthy($row['present'] ?? false)) {
            return null;
        }

        $comment = (string) ($row['comment'] ?? '');
        $ftype   = (string) ($row['ftype'] ?? '');

        if (
            !str_starts_with($comment, self::COMMENT_PREFIX)
            || preg_match('/^vector\((\d+)\)$/', $ftype, $m) !== 1
        ) {
            throw new VectorStoreException(
                "A table named {$table} exists but was not created by this plugin, so it is neither "
                . 'reused nor overwritten. Pick another collection name or move that table.',
                $operation,
            );
        }

        try {
            $meta   = json_decode(substr($comment, \strlen(self::COMMENT_PREFIX)), true, 4, JSON_THROW_ON_ERROR);
            $metric = VectorMetric::from((string) ($meta['metric'] ?? ''));

            $found = new VectorCollection($name, (int) $m[1], $metric);
        } catch (\Throwable $e) {
            throw new VectorStoreException("The metadata on table {$table} is unreadable.", $operation, $e);
        }

        return $this->known[$name] = $found;
    }

    private function tryDescribe(string $name): ?VectorCollection
    {
        try {
            return $this->describeTable($name, 'ensureCollection');
        } catch (\Throwable) {
            return null;
        }
    }

    private function requireCollection(string $name, string $operation): VectorCollection
    {
        VectorCollection::assertValidName($name);

        return $this->known[$name]
            ?? $this->describeTable($name, $operation)
            ?? throw VectorStoreException::collectionMissing($name, $operation);
    }

    /**
     * Connected to PostgreSQL, with the vector extension at a version that has HNSW. Once.
     */
    private function assertReady(string $operation): void
    {
        if ($this->ready) {
            return;
        }

        if ($this->db instanceof DriverAware && $this->db->driver() !== 'pgsql') {
            throw new VectorStoreException(
                \sprintf('pgvector needs PostgreSQL; the bound DatabasePort is "%s".', $this->db->driver()),
                $operation,
            );
        }

        try {
            $row = $this->db->queryOne("SELECT extversion FROM pg_extension WHERE extname = 'vector'");
        } catch (\Throwable $e) {
            throw $this->fail($operation, $e);
        }

        if ($row === null) {
            // Proposed, not run: CREATE EXTENSION needs privileges the application should not hold.
            throw new VectorStoreException(
                'The pgvector extension is not created in this database. A database administrator '
                . 'must run `CREATE EXTENSION vector;` (the extension files must be installed on the '
                . 'server first). This plugin never creates it.',
                $operation,
            );
        }

        $version = (string) $row['extversion'];

        if (version_compare($version, self::MIN_VERSION, '<')) {
            throw new VectorStoreException(
                "pgvector {$version} is installed; HNSW needs " . self::MIN_VERSION . ' or newer.',
                $operation,
            );
        }

        $this->extensionVersion = $version;
        $this->ready            = true;
    }

    /** @param list<VectorFilter> $filters */
    private static function assertFilters(array $filters): void
    {
        foreach ($filters as $filter) {
            if (!$filter instanceof VectorFilter) {
                throw new \InvalidArgumentException('Filters must be VectorFilter instances.');
            }
        }

        if (\count($filters) > VectorQuery::MAX_FILTERS) {
            throw new \InvalidArgumentException(\sprintf('At most %d filters.', VectorQuery::MAX_FILTERS));
        }
    }

    private static function opclass(VectorMetric $metric): string
    {
        return match ($metric) {
            VectorMetric::Cosine     => 'vector_cosine_ops',
            VectorMetric::Euclidean  => 'vector_l2_ops',
            VectorMetric::DotProduct => 'vector_ip_ops',
        };
    }

    private function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private static function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === 't' || $v === '1' || $v === 'true';
    }

    /** @return array<array-key, mixed> */
    private static function decode(string $json): array
    {
        $value = json_decode($json, true, 8, JSON_THROW_ON_ERROR);

        return \is_array($value) ? $value : [];
    }

    /**
     * Translate a driver failure. The message carries the operation and the
     * database's own first line — never a DSN, never a parameter value.
     */
    private function fail(string $operation, \Throwable $e): VectorStoreException
    {
        if ($e instanceof VectorStoreException) {
            return $e;
        }

        $reason = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        $reason = trim(strtok($reason, "\n") ?: '');

        $hint = str_contains($reason, 'type "vector" does not exist')
            ? ' The vector type is not on this connection\'s search_path: create the extension in a '
                . 'schema the connection searches, or add that schema to the search_path.'
            : '';

        return new VectorStoreException(
            "pgvector {$operation} failed: " . substr($reason, 0, 300) . '.' . $hint,
            $operation,
            $e,
        );
    }
}
