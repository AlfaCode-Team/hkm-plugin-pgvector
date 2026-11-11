<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Unit;

use AlfacodeTeam\Ground\Fakes\FakeDatabase;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DriverAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\DimensionMismatchException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorCollection;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMetric;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorQuery;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorRecord;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorStoreCapability;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorStoreException;
use PHPUnit\Framework\TestCase;
use Plugins\Pgvector\Infrastructure\Config\PgvectorConfig;
use Plugins\Pgvector\Infrastructure\Persistence\PgvectorStore;

/**
 * What the store refuses BEFORE it asks the database anything.
 *
 * "Refused before" is the property: each test asserts the fake saw NO write, and
 * where the refusal needs the collection's definition it queues the one catalog
 * answer and asserts nothing after it ran.
 */
final class PgvectorStoreGuardsTest extends TestCase
{
    /** A FakeDatabase that answers the readiness check and describes a 3-dimension cosine collection. */
    private static function db(): FakeDatabase
    {
        return (new FakeDatabase())
            ->onQuery('pg_extension', ['extversion' => '0.8.7'])
            ->onQuery('to_regclass', [
                'present' => true,
                'comment' => 'hkm-vector:{"metric":"cosine"}',
                'ftype'   => 'vector(3)',
            ]);
    }

    private static function store(FakeDatabase $db): PgvectorStore
    {
        return new PgvectorStore($db, PgvectorConfig::of());
    }

    private static function wrote(FakeDatabase $db): bool
    {
        foreach ($db->queries as $q) {
            if (preg_match('/^(UPSERT|INSERT|UPDATE|DELETE|CREATE|DROP|COMMENT)/i', $q['sql']) === 1) {
                return true;
            }
        }

        return false;
    }

    public function test_deleteWhere_with_no_filter_is_refused_before_any_statement(): void
    {
        $db = self::db();

        try {
            self::store($db)->deleteWhere('docs', []);
            self::fail('an empty filter list would be a truncate');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('at least one filter', $e->getMessage());
            self::assertStringContainsString('dropCollection', $e->getMessage());
        }

        self::assertSame([], $db->queries, 'not even the readiness check should have run');
    }

    public function test_duplicate_ids_in_a_batch_are_refused_before_any_statement(): void
    {
        $db = self::db();
        $v  = Vector::of([1, 0, 0]);

        try {
            self::store($db)->upsertMany('docs', [new VectorRecord('a', $v), new VectorRecord('b', $v), new VectorRecord('a', $v)]);
            self::fail('a batch holding one id twice is ambiguous');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('"a" appears twice', $e->getMessage());
        }

        self::assertSame([], $db->queries);
    }

    public function test_an_oversized_batch_is_refused_before_any_statement(): void
    {
        $db      = self::db();
        $records = [];

        for ($i = 0; $i <= PgvectorStore::MAX_BATCH; $i++) {
            $records[] = new VectorRecord("id{$i}", Vector::of([1, 0, 0]));
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 1000 records; 1001 were given/');

        try {
            self::store($db)->upsertMany('docs', $records);
        } finally {
            self::assertSame([], $db->queries);
        }
    }

    public function test_an_empty_batch_asks_nobody_anything(): void
    {
        $db = self::db();

        self::assertSame(0, self::store($db)->upsertMany('docs', []));
        self::assertSame([], $db->queries);
    }

    public function test_a_batch_with_one_wrong_length_vector_writes_nothing(): void
    {
        $db = self::db();

        try {
            self::store($db)->upsertMany('docs', [
                new VectorRecord('ok', Vector::of([1, 0, 0])),
                new VectorRecord('bad', Vector::of([1, 0])),
            ]);
            self::fail('a 2-dimension vector in a 3-dimension collection');
        } catch (DimensionMismatchException $e) {
            self::assertSame(3, $e->expected);
            self::assertSame(2, $e->actual);
        }

        self::assertFalse(self::wrote($db), 'validation is all-before-any-write');
        self::assertSame(0, $db->commits + $db->rollbacks, 'no transaction was even opened');
    }

    public function test_a_single_wrong_length_upsert_and_search_never_reach_the_database(): void
    {
        $db    = self::db();
        $store = self::store($db);

        try {
            $store->upsert('docs', new VectorRecord('a', Vector::of([1, 2])));
            self::fail('dimension mismatch on upsert');
        } catch (DimensionMismatchException) {
        }

        try {
            $store->search(VectorQuery::nearest('docs', Vector::of([1, 2, 3, 4]), 3));
            self::fail('dimension mismatch on search');
        } catch (DimensionMismatchException $e) {
            self::assertSame(4, $e->actual);
        }

        self::assertFalse(self::wrote($db));
        self::assertFalse($db->ran('hkm_vec_docs" ORDER'), 'no search statement was sent');
        self::assertSame(0, $db->commits + $db->rollbacks);
    }

    public function test_a_zero_vector_is_refused_in_a_cosine_collection_for_write_and_query(): void
    {
        $db    = self::db();
        $store = self::store($db);

        foreach ([
            static fn () => $store->upsert('docs', new VectorRecord('z', Vector::of([0, 0, 0]))),
            static fn () => $store->search(VectorQuery::nearest('docs', Vector::of([0, 0, 0]), 3)),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('cosine is undefined for the zero vector');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('undefined for the zero vector', $e->getMessage());
                self::assertNotInstanceOf(DimensionMismatchException::class, $e);
            }
        }

        self::assertFalse(self::wrote($db));
    }

    public function test_a_missing_collection_is_an_error_not_an_empty_result(): void
    {
        $db = (new FakeDatabase())
            ->onQuery('pg_extension', ['extversion' => '0.8.7'])
            ->onQuery('to_regclass', ['present' => false, 'comment' => null, 'ftype' => null]);

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessageMatches('/collection "ghost" does not exist/');

        self::store($db)->search(VectorQuery::nearest('ghost', Vector::of([1, 0, 0]), 3));
    }

    public function test_a_table_this_plugin_did_not_create_is_never_adopted_or_dropped(): void
    {
        $db = (new FakeDatabase())
            ->onQuery('pg_extension', ['extversion' => '0.8.7'])
            ->onQuery('to_regclass', ['present' => true, 'comment' => 'someone elses table', 'ftype' => 'vector(3)']);

        try {
            self::store($db)->dropCollection('docs');
            self::fail('dropCollection() must not drop a table without this plugin\'s marker');
        } catch (VectorStoreException $e) {
            self::assertStringContainsString('was not created by this plugin', $e->getMessage());
        }

        self::assertFalse($db->ran('DROP TABLE'));
    }

    public function test_the_extension_missing_is_reported_with_the_command_to_run_and_never_run(): void
    {
        $db = (new FakeDatabase())->onQuery('pg_extension');

        try {
            self::store($db)->ensureCollection(new VectorCollection('docs', 3));
            self::fail('no extension, no collection');
        } catch (VectorStoreException $e) {
            self::assertStringContainsString('CREATE EXTENSION vector', $e->getMessage());
            self::assertStringContainsString('never creates it', $e->getMessage());
        }

        self::assertFalse($db->ran('CREATE EXTENSION'));
        self::assertFalse(self::wrote($db));
    }

    public function test_an_old_pgvector_without_hnsw_is_refused(): void
    {
        $db = (new FakeDatabase())->onQuery('pg_extension', ['extversion' => '0.4.4']);

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessageMatches('/pgvector 0\.4\.4 is installed; HNSW needs 0\.5\.0/');

        self::store($db)->collection('docs');
    }

    public function test_a_non_postgres_database_port_is_refused_by_name(): void
    {
        $mysql = new class implements DatabasePort, DriverAware {
            public function driver(): string { return 'mysql'; }
            public function query(string $sql, array $params = []): array { throw new \LogicException('must not be reached'); }
            public function queryOne(string $sql, array $params = []): ?array { throw new \LogicException('must not be reached'); }
            public function execute(string $sql, array $params = []): int { throw new \LogicException('must not be reached'); }
            public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { throw new \LogicException('must not be reached'); }
            public function lastInsertId(?string $sequence = null): string { return '0'; }
            public function beginTransaction(): void {}
            public function commit(): void {}
            public function rollback(): void {}
            public function inTransaction(): bool { return false; }
        };

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessageMatches('/needs PostgreSQL; the bound DatabasePort is "mysql"/');

        (new PgvectorStore($mysql, PgvectorConfig::of()))->ensureCollection(new VectorCollection('docs', 3, VectorMetric::Cosine));
    }

    public function test_a_driver_failure_is_translated_and_carries_no_parameter_value(): void
    {
        $db = self::db()->failOn('select id, d', new \PDOException('SQLSTATE[XX000]: boom'));

        try {
            self::store($db)->search(VectorQuery::nearest('docs', Vector::of([1, 0, 0]), 3)->where(VectorFilter::eq('secret', 'hunter2')));
            self::fail('the driver error must surface');
        } catch (VectorStoreException $e) {
            self::assertSame('search', $e->operation);
            self::assertInstanceOf(\PDOException::class, $e->getPrevious());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }

        self::assertSame(1, $db->rollbacks, 'the read-only transaction is rolled back on failure');
    }

    /**
     * `if (!hasHnswIndex()) return $rows` is a PERFORMANCE guard only: without an
     * index pass 1 was already an exact scan, so the exact pass would return the
     * identical rows. No result-level test can see it — a revert check proved
     * that — so it is pinned by counting the statements instead.
     */
    public function test_with_no_hnsw_index_a_short_result_is_not_scanned_a_second_time(): void
    {
        // FakeDatabase answers with the FIRST matcher whose needle the SQL contains, and the index
        // query also contains `to_regclass`, so the index matcher has to be registered before it.
        $db = (new FakeDatabase())
            ->onQuery('am.amname')   // no hnsw/ivfflat index
            ->onQuery('pg_extension', ['extversion' => '0.8.7'])
            ->onQuery('to_regclass', ['present' => true, 'comment' => 'hkm-vector:{"metric":"cosine"}', 'ftype' => 'vector(3)'])
            ->onQuery('set_config')
            ->onQuery('select id, d', ['id' => 'a', 'd' => 0.5, 'payload' => '{}']);

        $got = self::store($db)->search(VectorQuery::nearest('docs', Vector::of([1, 0, 0]), 5));

        self::assertCount(1, $got);

        $passes = array_filter($db->queries, static fn (array $q): bool => str_contains(strtolower($q['sql']), 'select id, d'));
        self::assertCount(1, $passes, 'one pass only: there is no index for the first one to have been wrong about');
    }

    public function test_with_an_hnsw_index_a_short_result_is_repeated_exactly(): void
    {
        $db = (new FakeDatabase())
            ->onQuery('am.amname', ['name' => 'hkm_vec_docs_hnsw', 'def' => 'x', 'am' => 'hnsw'])
            ->onQuery('pg_extension', ['extversion' => '0.8.7'])
            ->onQuery('to_regclass', ['present' => true, 'comment' => 'hkm-vector:{"metric":"cosine"}', 'ftype' => 'vector(3)'])
            ->onQuery('set_config')
            ->onQuery('select id, d', ['id' => 'a', 'd' => 0.5, 'payload' => '{}']);

        self::store($db)->search(VectorQuery::nearest('docs', Vector::of([1, 0, 0]), 5));

        $passes = array_filter($db->queries, static fn (array $q): bool => str_contains(strtolower($q['sql']), 'select id, d'));
        self::assertCount(2, $passes, 'approximate pass came up short (1 of 5) and an index exists: exact pass follows');

        $settings = array_map(static fn (array $q): string => (string) ($q['params']['n'] ?? ''), array_filter($db->queries, static fn (array $q): bool => str_contains($q['sql'], 'set_config')));
        self::assertContains('hnsw.ef_search', $settings);
        self::assertContains('enable_indexscan', $settings);
    }

    public function test_exact_search_is_declared_and_describe_names_no_connection(): void
    {
        $store = self::store(self::db());

        self::assertTrue($store->supports(VectorStoreCapability::ExactSearch));
        self::assertSame('pgvector (PostgreSQL, over DatabasePort)', $store->describe());
    }
}
