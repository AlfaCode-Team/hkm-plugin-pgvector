<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Integration;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMatch;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMetric;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorQuery;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Pgvector\Infrastructure\Config\PgvectorConfig;
use Plugins\Pgvector\Infrastructure\Persistence\PgvectorStore;
use Tests\Plugins\Pgvector\Support\Brute;
use Tests\Plugins\Pgvector\Support\IntegrationTestCase;
use Tests\Plugins\Pgvector\Support\RecordingDatabase;

final class SearchTest extends IntegrationTestCase
{
    /**
     * The five vectors and the query below, and every expected distance in
     * {@see self::expectations()}, were computed in Python from the definitions
     * (cosine = 1 - a.b/(|a||b|), L2 = sqrt(sum (a-b)^2), dot = -a.b), NOT by
     * this code, the store, or pgvector:
     *
     *   V = {a:[1,0,0], b:[0,1,0], c:[1,1,0], d:[2,0,0], e:[1,2,2]},  q = [3,4,0]
     */
    private const VECTORS = ['a' => [1, 0, 0], 'b' => [0, 1, 0], 'c' => [1, 1, 0], 'd' => [2, 0, 0], 'e' => [1, 2, 2]];

    private const QUERY = [3, 4, 0];

    /** @return iterable<string, array{VectorMetric, list<array{string, float}>}> */
    public static function expectations(): iterable
    {
        // a and d are a TIE under cosine (both 0.4): same direction. The port orders ties by id.
        yield 'cosine' => [VectorMetric::Cosine, [
            ['c', 0.010050506338833531], ['b', 0.19999999999999996], ['e', 0.2666666666666667], ['a', 0.4], ['d', 0.4],
        ]];

        yield 'euclidean' => [VectorMetric::Euclidean, [
            ['e', 3.4641016151377544], ['c', 3.6055512754639896], ['d', 4.123105625617661], ['b', 4.242640687119286], ['a', 4.47213595499958],
        ]];

        // NEGATIVE inner product: -11 is closer than -3. pgvector's <#> sign, kept by the port.
        yield 'dot' => [VectorMetric::DotProduct, [
            ['e', -11.0], ['c', -7.0], ['d', -6.0], ['b', -4.0], ['a', -3.0],
        ]];
    }

    /** @param list<array{string, float}> $expected */
    #[DataProvider('expectations')]
    public function test_knn_order_and_distances_match_hand_computed_values(VectorMetric $metric, array $expected): void
    {
        $c = $this->created('knn_' . $metric->value, 3, $metric);

        foreach (self::VECTORS as $id => $v) {
            $this->store->upsert($c->name, new VectorRecord($id, Vector::of($v)));
        }

        // Both modes, and with and without an index: four routes to one answer.
        foreach ([false, true] as $indexed) {
            if ($indexed) {
                $this->store->ensureIndex(new VectorIndex($c->name));
            }

            foreach ([false, true] as $exact) {
                $query = VectorQuery::nearest($c->name, Vector::of(self::QUERY), 5);
                $got   = $this->store->search($exact ? $query->exact() : $query);

                self::assertSame(array_column($expected, 0), array_map(static fn (VectorMatch $m): string => $m->id, $got), "{$metric->value} indexed=" . (int) $indexed . ' exact=' . (int) $exact);

                foreach ($got as $i => $match) {
                    self::assertEqualsWithDelta($expected[$i][1], $match->distance, 1e-6, "{$match->id} under {$metric->value}");
                }
            }
        }
    }

    public function test_k_smaller_than_the_collection_returns_exactly_the_k_closest(): void
    {
        $c = $this->created('k2', 3, VectorMetric::Euclidean);

        foreach (self::VECTORS as $id => $v) {
            $this->store->upsert($c->name, new VectorRecord($id, Vector::of($v)));
        }

        $got = $this->store->search(VectorQuery::nearest($c->name, Vector::of(self::QUERY), 2));

        self::assertSame(['e', 'c'], array_map(static fn (VectorMatch $m): string => $m->id, $got));
    }

    public function test_k_larger_than_the_collection_returns_everything_not_an_error(): void
    {
        $c = $this->created('kbig', 3);

        foreach (self::VECTORS as $id => $v) {
            $this->store->upsert($c->name, new VectorRecord($id, Vector::of($v)));
        }
        $this->store->ensureIndex(new VectorIndex($c->name));

        self::assertCount(5, $this->store->search(VectorQuery::nearest($c->name, Vector::of(self::QUERY), 1000)));
    }

    public function test_equal_distances_are_ordered_by_id_bytewise_not_by_locale(): void
    {
        $c = $this->created('ties', 2);

        // Identical vectors: every distance ties. Under the default en_US collation these sort a,b,B;
        // bytewise ("C") they sort B,a,b — measured with a plain `ORDER BY id` on this server.
        foreach (['b', 'B', 'a'] as $id) {
            $this->store->upsert($c->name, new VectorRecord($id, Vector::of([1, 1])));
        }
        $this->store->upsert($c->name, new VectorRecord('far', Vector::of([-1, 1])));
        $this->store->ensureIndex(new VectorIndex($c->name));

        foreach ([false, true] as $exact) {
            $q   = VectorQuery::nearest($c->name, Vector::of([1, 1]), 4);
            $ids = array_map(static fn (VectorMatch $m): string => $m->id, $this->store->search($exact ? $q->exact() : $q));

            self::assertSame(['B', 'a', 'b', 'far'], $ids, 'exact=' . (int) $exact);
        }
    }

    public function test_orthogonal_dot_product_is_zero_not_negative_zero(): void
    {
        $c = $this->created('negzero', 2, VectorMetric::DotProduct);
        $this->store->upsert($c->name, new VectorRecord('ortho', Vector::of([0, 1])));

        $d = $this->store->search(VectorQuery::nearest($c->name, Vector::of([1, 0]), 1))[0]->distance;

        // pgvector's <#> printed "-0" for this (measured). -0.0 === 0.0 is true in PHP, so compare the string.
        self::assertSame('0', (string) $d);
    }

    public function test_without_payload_returns_ids_and_distances_only(): void
    {
        $c = $this->created('nopayload', 2);
        $this->store->upsert($c->name, new VectorRecord('a', Vector::of([1, 1]), ['k' => 'v']));

        $with    = $this->store->search(VectorQuery::nearest($c->name, Vector::of([1, 1]), 1))[0];
        $without = $this->store->search(VectorQuery::nearest($c->name, Vector::of([1, 1]), 1)->withoutPayload())[0];

        self::assertSame(['k' => 'v'], $with->payload);
        self::assertSame([], $without->payload);
        self::assertSame('a', $without->id);
    }

    public function test_the_plan_uses_the_hnsw_index_for_every_metric_and_not_when_exact_or_unindexed(): void
    {
        foreach (VectorMetric::cases() as $metric) {
            $c = $this->created('plan_' . $metric->value, 3, $metric);

            // A 5-row table is (rightly) seq-scanned whatever indexes exist — measured: the first
            // version of this test failed on exactly that. 1,500 rows and fresh statistics.
            mt_srand(11);
            $rows = [];

            for ($i = 0; $i < 1500; $i++) {
                $rows[] = new VectorRecord(sprintf('r%04d', $i), Vector::of(self::randomVector(3, nonZero: true)));
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                $this->store->upsertMany($c->name, $chunk);
            }

            $this->db->execute('ANALYZE "hkm_vec_' . $c->name . '"');

            $q     = VectorQuery::nearest($c->name, Vector::of(self::QUERY), 3);
            $index = 'hkm_vec_' . $c->name . '_hnsw';

            // Before an index there is nothing to use.
            self::assertStringNotContainsString($index, implode("\n", $this->store->explainSearch($q)));

            $this->store->ensureIndex(new VectorIndex($c->name));

            $approx = implode("\n", $this->store->explainSearch($q));
            self::assertStringContainsString("Index Scan using {$index}", $approx, $metric->value . " plan:\n" . $approx);

            $exact = implode("\n", $this->store->explainSearch($q, exact: true));
            self::assertStringNotContainsString($index, $exact, "exact() must not use the index:\n" . $exact);
            self::assertStringContainsString('Seq Scan', $exact);
        }
    }

    /**
     * pgvector, measured: with the default hnsw.ef_search of 40, an index scan
     * returned 40 rows for LIMIT 100. The store raises ef_search to k.
     *
     * The first version of this test had 400 rows and NO guard in the planner's
     * way, and a revert check showed removing the ef_search guard went unnoticed:
     * on a table that small PostgreSQL seq-scans, so the index — and with it
     * ef_search — never came into play. Hence 1,500 rows, the planner told not to
     * seq-scan, iterative scan off (with it on, PostgreSQL keeps scanning for more
     * candidates itself), and a PRECONDITION that the naive query at ef_search = 40
     * really does return 40.
     */
    public function test_k_above_ef_search_still_returns_k(): void
    {
        $store = new PgvectorStore($this->db, PgvectorConfig::of(efSearch: 40, iterativeScan: 'off'));
        $c     = $this->created('efk', 8);
        mt_srand(7);
        $records = [];

        for ($i = 0; $i < 1500; $i++) {
            $records[] = new VectorRecord(sprintf('r%04d', $i), Vector::of(self::randomVector(8)));
        }

        foreach (array_chunk($records, 500) as $chunk) {
            $store->upsertMany($c->name, $chunk);
        }

        $store->ensureIndex(new VectorIndex($c->name));
        $this->db->execute('ANALYZE "hkm_vec_' . $c->name . '"');

        $query = self::randomVector(8);

        $this->db->execute('SET enable_seqscan = off');
        $this->db->execute('SET enable_bitmapscan = off');

        try {
            $this->db->beginTransaction();
            $this->db->query("SELECT set_config('hnsw.ef_search', '40', true)");
            $this->db->query("SELECT set_config('hnsw.iterative_scan', 'off', true)");
            $naive = $this->db->query(
                'SELECT id FROM "hkm_vec_' . $c->name . '" ORDER BY embedding <=> CAST(:q AS vector) LIMIT 150',
                ['q' => json_encode($query)],
            );
            $this->db->rollback();
            self::assertCount(40, $naive, 'precondition: ef_search = 40 caps an index scan at 40 rows, whatever LIMIT says');

            $recording = new RecordingDatabase($this->db);
            $counted   = new PgvectorStore($recording, PgvectorConfig::of(efSearch: 40, iterativeScan: 'off'));

            $got = $counted->search(VectorQuery::nearest($c->name, Vector::of($query), 150));

            self::assertCount(150, $got, 'the store must have raised ef_search to k');
            // The RESULT alone cannot show the guard: without it the approximate pass returns 40, comes up
            // short, and the exact fallback returns 150 anyway (a revert check showed exactly that). What
            // the guard buys is not paying for the second pass, so that is what is pinned.
            self::assertSame(1, $recording->searchPasses(), 'one pass: ef_search was raised to k, so the index scan was not short');
            self::assertCount(150, array_unique(array_map(static fn (VectorMatch $m): string => $m->id, $got)));
        } finally {
            $this->db->execute('RESET enable_seqscan');
            $this->db->execute('RESET enable_bitmapscan');
        }
    }

    /**
     * THE test for the most important pgvector fact: a filtered HNSW query can
     * return fewer rows than match, or none, with no error.
     *
     * 3,000 vectors; 12 carry `rare = 1`. Whether the planner picks the HNSW
     * index for a filtered query is a cost decision, and on a table this small it
     * would usually (correctly) scan — hiding the defect. So the session is told
     * not to seq-scan or bitmap-scan, which reproduces the regime measured at
     * 30,000 vectors where the planner chose the index on its own and returned 0.
     * The precondition then runs the NAIVE query under the SAME settings and
     * asserts it comes up short, so this test cannot pass merely because nothing
     * was ever wrong. The expectation is brute force in PHP.
     *
     * Three configurations, because there are two independent ways the index
     * pass falls short: no iterative scan at all, and an iterative scan that gives
     * up (`max_scan_tuples`) before it has seen the matching rows.
     */
    public function test_a_selective_filter_still_returns_min_k_matches_when_the_index_alone_would_not(): void
    {
        $c = $this->created('rare', 16);
        mt_srand(99);

        $vectors = [];
        $records = [];

        for ($i = 0; $i < 3000; $i++) {
            $id           = sprintf('r%05d', $i);
            $vectors[$id] = self::randomVector(16);
            $records[]    = new VectorRecord($id, Vector::of($vectors[$id]), ['rare' => $i % 250 === 0 ? 1 : 0]);
        }

        foreach (array_chunk($records, 500) as $chunk) {
            $this->store->upsertMany($c->name, $chunk);
        }

        $this->store->ensureIndex(new VectorIndex($c->name));
        $this->db->execute('ANALYZE "hkm_vec_' . $c->name . '"');

        $query    = self::randomVector(16);
        $matching = array_values(array_filter(array_keys($vectors), static fn (string $id): bool => ((int) substr($id, 1)) % 250 === 0));
        self::assertCount(12, $matching);

        $expected10 = Brute::nearest(VectorMetric::Cosine, $vectors, $query, 10, $matching);

        $this->db->execute('SET enable_seqscan = off');
        $this->db->execute('SET enable_bitmapscan = off');

        try {
            foreach ([
                'no iterative scan'          => ['off', 20000],
                'iterative, gives up at 200' => ['relaxed_order', 200],
            ] as $label => [$mode, $maxScan]) {
                // PRECONDITION: the naive query is wrong on this data, under these settings.
                $this->db->beginTransaction();
                $this->db->query('SELECT set_config(:n, :v, true)', ['n' => 'hnsw.iterative_scan', 'v' => $mode]);
                $this->db->query('SELECT set_config(:n, :v, true)', ['n' => 'hnsw.max_scan_tuples', 'v' => (string) $maxScan]);
                $naive = $this->db->query(
                    'SELECT id FROM "hkm_vec_' . $c->name . '" WHERE payload @> CAST(:f AS jsonb) ORDER BY embedding <=> CAST(:q AS vector) LIMIT 10',
                    ['f' => '{"rare":1}', 'q' => json_encode($query)],
                );
                $this->db->rollback();
                self::assertLessThan(10, \count($naive), "precondition ({$label}): the naive query must return fewer than k");

                $store = new PgvectorStore($this->db, PgvectorConfig::of(iterativeScan: $mode, maxScanTuples: $maxScan));
                $got   = $store->search(VectorQuery::nearest($c->name, Vector::of($query), 10)->where(VectorFilter::eq('rare', 1)));

                self::assertSame($expected10, array_map(static fn (VectorMatch $m): string => $m->id, $got), $label);

                // Fewer than k genuinely matching is reported as fewer, not padded: 12 match, ask for 20.
                $all = $store->search(VectorQuery::nearest($c->name, Vector::of($query), 20)->where(VectorFilter::eq('rare', 1)));
                self::assertEqualsCanonicalizing($matching, array_map(static fn (VectorMatch $m): string => $m->id, $all), $label);

                // Matching nothing at all is an empty result — after the exact pass confirmed it.
                self::assertSame([], $store->search(VectorQuery::nearest($c->name, Vector::of($query), 10)->where(VectorFilter::eq('rare', 7))), $label);
            }
        } finally {
            $this->db->execute('RESET enable_seqscan');
            $this->db->execute('RESET enable_bitmapscan');
        }
    }

    public function test_exact_search_equals_brute_force_on_random_data(): void
    {
        foreach ([VectorMetric::Cosine, VectorMetric::Euclidean, VectorMetric::DotProduct] as $metric) {
            $c = $this->created('exact_' . $metric->value, 12, $metric);
            mt_srand(31);
            $vectors = [];
            $records = [];

            for ($i = 0; $i < 300; $i++) {
                $id           = sprintf('r%03d', $i);
                $vectors[$id] = self::randomVector(12);
                $records[]    = new VectorRecord($id, Vector::of($vectors[$id]));
            }

            $this->store->upsertMany($c->name, $records);
            $this->store->ensureIndex(new VectorIndex($c->name));
            $query = self::randomVector(12);

            $got = array_map(
                static fn (VectorMatch $m): string => $m->id,
                $this->store->search(VectorQuery::nearest($c->name, Vector::of($query), 25)->exact()),
            );

            self::assertSame(Brute::nearest($metric, $vectors, $query, 25), $got, $metric->value);
        }
    }

    /**
     * The approximate pass is allowed to miss a true neighbour. What it is held
     * to is a floor, and the floor is loose on purpose: HNSW's graph is built
     * with a random level assignment, so recall is not reproducible run to run.
     */
    public function test_approximate_recall_on_random_data_is_high(): void
    {
        $c = $this->created('recall', 12);
        mt_srand(5);
        $vectors = [];
        $records = [];

        for ($i = 0; $i < 1500; $i++) {
            $id           = sprintf('r%04d', $i);
            $vectors[$id] = self::randomVector(12);
            $records[]    = new VectorRecord($id, Vector::of($vectors[$id]));
        }

        foreach (array_chunk($records, 500) as $chunk) {
            $this->store->upsertMany($c->name, $chunk);
        }
        $this->store->ensureIndex(new VectorIndex($c->name));

        $hits  = 0;
        $total = 0;

        for ($n = 0; $n < 10; $n++) {
            $query = self::randomVector(12);
            $truth = Brute::nearest(VectorMetric::Cosine, $vectors, $query, 10);
            $got   = array_map(static fn (VectorMatch $m): string => $m->id, $this->store->search(VectorQuery::nearest($c->name, Vector::of($query), 10)));

            self::assertCount(10, $got);
            $hits  += \count(array_intersect($truth, $got));
            $total += 10;
        }

        self::assertGreaterThanOrEqual(0.8, $hits / $total, "recall@10 was {$hits}/{$total}");
    }

    public function test_search_settings_do_not_leak_onto_the_connection(): void
    {
        $c = $this->created('leak', 3);
        $this->store->upsert($c->name, new VectorRecord('a', Vector::of([1, 0, 0])));
        $this->store->ensureIndex(new VectorIndex($c->name));

        $before = $this->settings();

        $this->store->search(VectorQuery::nearest($c->name, Vector::of([1, 0, 0]), 5));
        $this->store->search(VectorQuery::nearest($c->name, Vector::of([1, 0, 0]), 5)->exact());

        self::assertSame($before, $this->settings(), 'set_config(..., true) is transaction-local and the pass is rolled back');
        self::assertFalse($this->db->inTransaction());
    }

    public function test_inside_a_callers_transaction_the_settings_are_undone_and_the_transaction_survives(): void
    {
        $c = $this->created('nested', 3);
        $this->store->upsert($c->name, new VectorRecord('a', Vector::of([1, 0, 0])));
        $this->store->ensureIndex(new VectorIndex($c->name));

        $this->db->beginTransaction();
        $this->store->upsert($c->name, new VectorRecord('b', Vector::of([0, 1, 0])));
        $before = $this->settings();

        $got = $this->store->search(VectorQuery::nearest($c->name, Vector::of([1, 0, 0]), 5));

        self::assertSame($before, $this->settings(), 'a savepoint rollback undoes SET LOCAL (measured)');
        self::assertTrue($this->db->inTransaction(), 'the caller still holds its transaction');
        self::assertCount(2, $got, 'and the search saw the caller\'s uncommitted write');

        $this->db->rollback();
        self::assertNull($this->store->get($c->name, 'b'));
    }

    /** @return array<string, string> */
    private function settings(): array
    {
        $out = [];

        foreach (['hnsw.ef_search', 'hnsw.iterative_scan', 'hnsw.max_scan_tuples', 'enable_indexscan'] as $name) {
            $out[$name] = (string) $this->db->queryOne('SELECT current_setting(:n, true) AS v', ['n' => $name])['v'];
        }

        return $out;
    }

    /** @return list<float> */
    private static function randomVector(int $dims, bool $nonZero = false): array
    {
        $v = [];

        for ($i = 0; $i < $dims; $i++) {
            $v[] = mt_rand(-1000, 1000) / 1000.0;
        }

        // A random cosine vector is never exactly zero in practice; the guard is for the 3-dimension
        // case, where a refusal would otherwise be a one-in-billions flake rather than a clear failure.
        if ($nonZero && Vector::of($v)->isZero()) {
            $v[0] = 1.0;
        }

        return $v;
    }
}
