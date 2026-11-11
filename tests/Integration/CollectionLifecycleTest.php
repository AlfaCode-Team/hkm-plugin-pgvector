<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Integration;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorCollection;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMetric;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorOutcome;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorRecord;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorStoreException;
use Plugins\Pgvector\Infrastructure\Config\PgvectorConfig;
use Plugins\Pgvector\Infrastructure\Persistence\PgvectorStore;
use Tests\Plugins\Pgvector\Support\IntegrationTestCase;

final class CollectionLifecycleTest extends IntegrationTestCase
{
    public function test_create_then_already_exists_then_conflict_on_either_difference(): void
    {
        $c = $this->collection('life', 3, VectorMetric::Cosine);

        self::assertSame(VectorOutcome::Created, $this->store->ensureCollection($c)->outcome);
        self::assertSame(VectorOutcome::AlreadyExists, $this->store->ensureCollection($c)->outcome);

        $dims = $this->store->ensureCollection(new VectorCollection($c->name, 4, VectorMetric::Cosine));
        self::assertSame(VectorOutcome::Conflict, $dims->outcome);
        self::assertFalse($dims->isSatisfied());
        self::assertStringContainsString('dimension is 3, wanted 4', $dims->detail);
        self::assertStringContainsString('NEW collection and a re-embed', $dims->detail);

        $metric = $this->store->ensureCollection(new VectorCollection($c->name, 3, VectorMetric::Euclidean));
        self::assertSame(VectorOutcome::Conflict, $metric->outcome);
        self::assertStringContainsString('metric is cosine, wanted euclidean', $metric->detail);

        // Nothing was changed by either conflict.
        self::assertTrue($c->equals($this->freshStore()->collection($c->name) ?? throw new \RuntimeException('gone')));
    }

    public function test_the_definition_is_read_back_from_the_database_not_from_a_cache(): void
    {
        foreach ([VectorMetric::Cosine, VectorMetric::Euclidean, VectorMetric::DotProduct] as $metric) {
            $c = $this->created('meta_' . $metric->value, 7, $metric);

            $read = $this->freshStore()->collection($c->name);

            self::assertNotNull($read);
            self::assertSame(7, $read->dimensions);
            self::assertSame($metric, $read->metric);
        }

        self::assertNull($this->freshStore()->collection('it_no_such_collection'));
    }

    public function test_the_table_carries_the_layout_the_docs_describe(): void
    {
        $c = $this->created('layout', 5);

        $table = 'hkm_vec_' . $c->name;
        $cols  = $this->db->query(
            "SELECT column_name, data_type, collation_name FROM information_schema.columns WHERE table_name = :t ORDER BY ordinal_position",
            ['t' => $table],
        );

        self::assertSame(['id', 'embedding', 'payload'], array_column($cols, 'column_name'));
        self::assertSame('jsonb', $cols[2]['data_type']);
        self::assertSame('C', $cols[0]['collation_name'], 'ties are ordered bytewise, not by the cluster locale');

        $gin = $this->db->query(
            "SELECT indexdef FROM pg_indexes WHERE tablename = :t AND indexdef LIKE '%gin%'",
            ['t' => $table],
        );
        self::assertCount(1, $gin);
        self::assertStringContainsString('jsonb_path_ops', $gin[0]['indexdef']);
    }

    public function test_a_foreign_table_of_the_same_name_is_neither_adopted_nor_dropped(): void
    {
        $c     = $this->collection('foreign', 3);
        $table = 'hkm_vec_' . $c->name;
        $this->trackRawTable($table);
        $this->db->execute("CREATE TABLE \"{$table}\" (id int)");

        try {
            $this->store->ensureCollection($c);
            self::fail('a table this plugin did not create must not be adopted');
        } catch (VectorStoreException $e) {
            self::assertStringContainsString('was not created by this plugin', $e->getMessage());
        }

        try {
            $this->store->dropCollection($c->name);
            self::fail('and must not be dropped');
        } catch (VectorStoreException) {
        }

        $still = $this->db->queryOne('SELECT to_regclass(CAST(:t AS text)) IS NOT NULL AS p', ['t' => $table]);
        self::assertTrue($still['p'], 'the foreign table is still there');
    }

    public function test_drop_collection_removes_it_and_is_false_the_second_time(): void
    {
        $c = $this->created('drop', 3);
        $this->store->upsert($c->name, new VectorRecord('a', Vector::of([1, 0, 0])));

        self::assertTrue($this->store->dropCollection($c->name));
        self::assertFalse($this->store->dropCollection($c->name));
        self::assertNull($this->store->collection($c->name));
    }

    public function test_a_second_connection_creating_the_same_collection_gets_already_exists(): void
    {
        $c = $this->collection('twice', 3);

        self::assertSame(VectorOutcome::Created, $this->store->ensureCollection($c)->outcome);
        self::assertSame(VectorOutcome::AlreadyExists, $this->freshStore()->ensureCollection($c)->outcome);
    }

    public function test_index_created_already_exists_conflict_and_the_operator_class_follows_the_metric(): void
    {
        $expected = [
            'cosine'    => 'vector_cosine_ops',
            'euclidean' => 'vector_l2_ops',
            'dot'       => 'vector_ip_ops',
        ];

        foreach (VectorMetric::cases() as $metric) {
            $c     = $this->created('idx_' . $metric->value, 4, $metric);
            $index = new VectorIndex($c->name, 8, 32);

            $first = $this->store->ensureIndex($index);
            self::assertSame(VectorOutcome::Created, $first->outcome);
            self::assertSame('hkm_vec_' . $c->name . '_hnsw', $first->name);

            $defs = $this->store->indexDefinitions($c->name);
            self::assertCount(1, $defs);
            self::assertStringContainsString($expected[$metric->value], $defs[0]['definition']);
            self::assertStringContainsString("m='8'", $defs[0]['definition']);

            self::assertSame(VectorOutcome::AlreadyExists, $this->store->ensureIndex($index)->outcome);

            $changed = $this->store->ensureIndex(new VectorIndex($c->name, 16, 64));
            self::assertSame(VectorOutcome::Conflict, $changed->outcome);
            self::assertStringContainsString('m is 8, wanted 16', $changed->detail);
            self::assertStringContainsString('ef_construction is 32, wanted 64', $changed->detail);

            // The conflict changed nothing: still exactly the original, still one index.
            $after = $this->store->indexDefinitions($c->name);
            self::assertCount(1, $after);
            self::assertStringContainsString("m='8'", $after[0]['definition']);
        }
    }

    public function test_an_index_of_another_kind_is_a_conflict_not_a_second_index(): void
    {
        $c = $this->created('ivf', 4);
        $this->db->execute('CREATE INDEX "foreign_ivf_' . $c->name . '" ON "hkm_vec_' . $c->name . '" USING ivfflat (embedding vector_cosine_ops) WITH (lists = 1)');

        $r = $this->store->ensureIndex(new VectorIndex($c->name));

        self::assertSame(VectorOutcome::Conflict, $r->outcome);
        self::assertStringContainsString('ivfflat', $r->detail);
        self::assertCount(1, $this->store->indexDefinitions($c->name));
    }

    public function test_an_hnsw_index_with_the_wrong_operator_class_is_a_conflict(): void
    {
        // Built by hand with L2 on a COSINE collection: queries would use `<=>`, which that index
        // cannot serve — every search a full scan with no error (measured).
        $c = $this->created('wrongops', 4, VectorMetric::Cosine);
        $this->db->execute('CREATE INDEX "foreign_l2_' . $c->name . '" ON "hkm_vec_' . $c->name . '" USING hnsw (embedding vector_l2_ops) WITH (m = 16, ef_construction = 64)');

        $r = $this->store->ensureIndex(new VectorIndex($c->name));

        self::assertSame(VectorOutcome::Conflict, $r->outcome);
        self::assertStringContainsString('operator class is vector_l2_ops, collection needs vector_cosine_ops', $r->detail);
        self::assertCount(1, $this->store->indexDefinitions($c->name), 'and no second index was built beside it');
    }

    public function test_drop_index_and_ensure_again(): void
    {
        $c = $this->created('dropidx', 4);

        self::assertFalse($this->store->dropIndex($c->name), 'nothing to drop yet');
        $this->store->ensureIndex(new VectorIndex($c->name));
        self::assertTrue($this->store->dropIndex($c->name));
        self::assertSame([], $this->store->indexDefinitions($c->name));
        self::assertSame(VectorOutcome::Created, $this->store->ensureIndex(new VectorIndex($c->name))->outcome);
    }

    public function test_an_index_on_a_missing_collection_is_an_error(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $this->store->ensureIndex(new VectorIndex('it_nothing_here'));
    }

    public function test_the_database_itself_refuses_a_wrong_length_vector_behind_the_port_check(): void
    {
        $c = $this->created('dimdb', 3);

        try {
            $this->db->execute(
                'INSERT INTO "hkm_vec_' . $c->name . '" (id, embedding) VALUES (:i, CAST(:e AS vector))',
                ['i' => 'x', 'e' => '[1,2]'],
            );
            self::fail('pgvector must refuse a 2-dimension vector in a vector(3) column');
        } catch (\RuntimeException $e) {
            // The Database plugin wraps PDOException; the DRIVER's text is in the previous.
            $driver = $e->getPrevious()?->getMessage() ?? $e->getMessage();
            self::assertStringContainsString('expected 3 dimensions, not 2', $driver);
        }
    }

    public function test_the_extension_missing_is_reported_by_a_database_without_it(): void
    {
        $noExt = $_SERVER['PGVECTOR_TEST_NOEXT_DB'] ?? getenv('PGVECTOR_TEST_NOEXT_DB');

        if (!\is_string($noExt) || $noExt === '') {
            self::markTestSkipped('PGVECTOR_TEST_NOEXT_DB (a scratch database WITHOUT the extension) is not set. This path ran only in the Unit suite.');
        }

        $_SERVER['PGVECTOR_TEST_DB_SAVED'] = $_SERVER['PGVECTOR_TEST_DB'] ?? '';
        $_SERVER['PGVECTOR_TEST_DB']       = $noExt;

        try {
            $port  = (int) ($_SERVER['PGVECTOR_TEST_PORT'] ?? getenv('PGVECTOR_TEST_PORT'));
            $store = new PgvectorStore(self::connect($port), PgvectorConfig::of());

            try {
                $store->ensureCollection(new VectorCollection('it_noext', 3));
                self::fail('no extension, no collection');
            } catch (VectorStoreException $e) {
                self::assertStringContainsString('CREATE EXTENSION vector', $e->getMessage());
            }
        } finally {
            $_SERVER['PGVECTOR_TEST_DB'] = $_SERVER['PGVECTOR_TEST_DB_SAVED'];
            unset($_SERVER['PGVECTOR_TEST_DB_SAVED']);
        }
    }

    private function freshStore(): PgvectorStore
    {
        return new PgvectorStore($this->db, PgvectorConfig::of());
    }
}
