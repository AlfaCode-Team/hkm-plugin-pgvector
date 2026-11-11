<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Integration;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorRecord;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorStoreException;
use Plugins\Pgvector\Infrastructure\Config\PgvectorConfig;
use Plugins\Pgvector\Infrastructure\Persistence\PgvectorStore;
use Tests\Plugins\Pgvector\Support\FailingDatabase;
use Tests\Plugins\Pgvector\Support\IntegrationTestCase;

final class WriteReadTest extends IntegrationTestCase
{
    public function test_a_record_round_trips_with_float32_precision(): void
    {
        $c = $this->created('rt', 3);

        $this->store->upsert($c->name, new VectorRecord('a', Vector::of([0.1, 0.123456789012, 1.0e-10]), ['s' => 'x', 'i' => 7, 'f' => 2.5, 'b' => true]));

        $got = $this->store->get($c->name, 'a');

        self::assertNotNull($got);
        self::assertSame('a', $got->id);
        // pgvector stores float32: 0.123456789012 comes back as 0.12345679 (measured). Hand-computed
        // bound: float32 has 24 bits, relative error <= 2^-24 ~= 6e-8.
        self::assertEqualsWithDelta([0.1, 0.123456789012, 1.0e-10], $got->vector->toArray(), 1e-7);
        self::assertNotSame(0.123456789012, $got->vector->toArray()[1], 'it is NOT bit-identical, which is why the docs say never compare with ===');
        // jsonb does not preserve key order (it sorts by key length, then bytes): the map is the
        // same, the iteration order is not — and the types are (7 stays int, 2.5 float, true bool).
        $payload = $got->payload;
        ksort($payload);
        self::assertSame(['b' => true, 'f' => 2.5, 'i' => 7, 's' => 'x'], $payload);
    }

    public function test_upsert_replaces_vector_and_payload_it_does_not_merge(): void
    {
        $c = $this->created('replace', 2);

        $this->store->upsert($c->name, new VectorRecord('a', Vector::of([1, 0]), ['keep' => 'no', 'old' => 1]));
        $this->store->upsert($c->name, new VectorRecord('a', Vector::of([0, 1]), ['new' => 2]));

        $got = $this->store->get($c->name, 'a');

        self::assertSame([0.0, 1.0], $got?->vector->toArray());
        self::assertSame(['new' => 2], $got?->payload, 'a payload field absent from the new record is gone');
        self::assertSame(1, $this->store->count($c->name));
    }

    public function test_an_empty_payload_is_stored_as_an_object_so_filters_still_work(): void
    {
        $c = $this->created('empty', 2);

        $this->store->upsertMany($c->name, [
            new VectorRecord('bare', Vector::of([1, 0])),
            new VectorRecord('tagged', Vector::of([0, 1]), ['k' => 'v']),
        ]);

        // json_encode([]) is `[]`, a jsonb ARRAY; containment against it is false for every filter and
        // `@>` against an array with an object operand never errors, so the defect would be silent.
        $types = $this->db->query('SELECT id, jsonb_typeof(payload) AS t FROM "hkm_vec_' . $c->name . '" ORDER BY id');
        self::assertSame([['id' => 'bare', 't' => 'object'], ['id' => 'tagged', 't' => 'object']], $types);

        self::assertSame(1, $this->store->count($c->name, [VectorFilter::eq('k', 'v')]));
        self::assertSame([], $this->store->get($c->name, 'bare')?->payload);
    }

    public function test_get_of_a_missing_id_is_null_and_of_a_missing_collection_is_an_error(): void
    {
        $c = $this->created('get', 2);

        self::assertNull($this->store->get($c->name, 'nope'));

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $this->store->get('it_missing_collection', 'a');
    }

    public function test_upsert_many_is_all_or_nothing_when_the_database_fails_mid_batch(): void
    {
        $c = $this->created('atomic', 2);
        $this->store->upsert($c->name, new VectorRecord('pre', Vector::of([1, 1]), ['was' => 'here']));

        $failing = new PgvectorStore(new FailingDatabase($this->db, failOnUpsert: 3), PgvectorConfig::of());
        $batch   = [];

        foreach (['a', 'b', 'c', 'd'] as $i => $id) {
            $batch[] = new VectorRecord($id, Vector::of([$i + 1, 1]));
        }

        try {
            $failing->upsertMany($c->name, $batch);
            self::fail('the third write was made to fail');
        } catch (VectorStoreException $e) {
            self::assertSame('upsertMany', $e->operation);
            self::assertStringContainsString('simulated mid-batch failure', $e->getMessage());
        }

        // The two writes that SUCCEEDED before the failure are rolled back; the earlier record is untouched.
        self::assertSame(1, $this->store->count($c->name));
        self::assertNull($this->store->get($c->name, 'a'));
        self::assertNull($this->store->get($c->name, 'b'));
        self::assertSame(['was' => 'here'], $this->store->get($c->name, 'pre')?->payload);
        self::assertFalse($this->db->inTransaction(), 'and no transaction was left open');
    }

    public function test_a_full_batch_commits_and_reports_the_count(): void
    {
        $c       = $this->created('batch', 2);
        $records = [];

        for ($i = 1; $i <= 50; $i++) {
            $records[] = new VectorRecord("r{$i}", Vector::of([$i, 1]), ['n' => $i]);
        }

        self::assertSame(50, $this->store->upsertMany($c->name, $records));
        self::assertSame(50, $this->store->count($c->name));
        self::assertSame(10, $this->store->count($c->name, [VectorFilter::gt('n', 40)]));
    }

    public function test_delete_and_delete_where(): void
    {
        $c = $this->created('del', 2);

        foreach ([['a', 'x', 1], ['b', 'x', 2], ['c', 'y', 3], ['d', 'x', 4]] as [$id, $src, $n]) {
            $this->store->upsert($c->name, new VectorRecord($id, Vector::of([1, $n]), ['source' => $src, 'n' => $n]));
        }

        self::assertTrue($this->store->delete($c->name, 'a'));
        self::assertFalse($this->store->delete($c->name, 'a'));

        // Every remaining chunk of source x — the "re-embed this document" use.
        self::assertSame(2, $this->store->deleteWhere($c->name, [VectorFilter::eq('source', 'x')]));
        self::assertSame(1, $this->store->count($c->name));
        self::assertNotNull($this->store->get($c->name, 'c'));

        self::assertSame(0, $this->store->deleteWhere($c->name, [VectorFilter::eq('source', 'never')]));
        self::assertSame(1, $this->store->count($c->name), 'a filter matching nothing deletes nothing');
    }

    public function test_hostile_ids_and_payload_text_are_data_not_sql(): void
    {
        $c    = $this->created('evil', 2);
        $evil = "'); DROP TABLE hkm_vec_{$c->name}; --";

        $this->store->upsert($c->name, new VectorRecord($evil, Vector::of([1, 2]), ['k' => $evil]));

        self::assertSame($evil, $this->store->get($c->name, $evil)?->id);
        self::assertSame(1, $this->store->count($c->name, [VectorFilter::eq('k', $evil)]));
        self::assertSame(1, $this->store->count($c->name, [VectorFilter::in('k', [$evil, 'x'])]));
        self::assertTrue($this->store->delete($c->name, $evil));
    }

    public function test_unicode_and_quotes_in_payload_survive(): void
    {
        $c = $this->created('uni', 2);
        $v = "héllo \"wörld\" 日本語 \u{1F600} back\\slash";

        $this->store->upsert($c->name, new VectorRecord('u', Vector::of([1, 2]), ['k' => $v]));

        self::assertSame($v, $this->store->get($c->name, 'u')?->payload['k']);
        self::assertSame(1, $this->store->count($c->name, [VectorFilter::eq('k', $v)]));
    }
}
