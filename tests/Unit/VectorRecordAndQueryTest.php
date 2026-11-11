<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorQuery;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VectorRecordAndQueryTest extends TestCase
{
    /** @return iterable<string, array{array<mixed>, string}> */
    public static function badPayloads(): iterable
    {
        yield 'null value'      => [['a' => null], 'is null'];
        yield 'nested array'    => [['a' => ['x' => 1]], 'is array'];
        yield 'list payload'    => [[1, 2], 'map of field name'];
        yield 'dotted key'      => [['a.b' => 1], 'payload field name must match'];
        yield 'NaN'             => [['a' => NAN], 'not finite'];
        yield 'NUL in string'   => [['a' => "x\0y"], 'NUL byte'];
        yield 'invalid UTF-8'   => [['a' => "\xB1\x31"], 'valid UTF-8'];
        yield 'object value'    => [['a' => new \stdClass()], 'is stdClass'];
        yield 'too many keys'   => [array_fill_keys(array_map(static fn (int $i): string => "k{$i}", range(1, 65)), 1), 'at most 64 keys'];
        yield 'over 32 KiB'     => [['a' => str_repeat('x', 40000)], 'at most 32768 bytes'];
    }

    /** @param array<mixed> $payload */
    #[DataProvider('badPayloads')]
    public function test_a_payload_that_is_not_flat_and_scalar_is_refused(array $payload, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($reason, '/') . '/');

        new VectorRecord('id', Vector::of([1]), $payload);
    }

    public function test_a_scalar_payload_and_the_32_KiB_edge_are_accepted(): void
    {
        $record = new VectorRecord('id', Vector::of([1]), ['s' => 'x', 'i' => 1, 'f' => 1.5, 'b' => false]);
        self::assertSame(1, $record->payload['i']);

        // {"a":"xxx…"} is 8 bytes of structure: 32768 - 8 characters fill it exactly.
        new VectorRecord('id', Vector::of([1]), ['a' => str_repeat('x', VectorRecord::MAX_PAYLOAD_BYTES - 8)]);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string}> */
    public static function badIds(): iterable
    {
        yield 'empty'     => [''];
        yield 'NUL'       => ["a\0b"];
        yield '256 bytes' => [str_repeat('a', 256)];
    }

    #[DataProvider('badIds')]
    public function test_ids_are_non_empty_bounded_and_nul_free(string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/vector id must be 1 to 255 bytes/');

        new VectorRecord($id, Vector::of([1]));
    }

    public function test_query_k_is_bounded_by_the_largest_ef_search(): void
    {
        $v = Vector::of([1, 2]);

        self::assertSame(1000, VectorQuery::nearest('docs', $v, 1000)->k);

        foreach ([0, -5, 1001] as $k) {
            try {
                VectorQuery::nearest('docs', $v, $k);
                self::fail("k={$k} should be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('between 1 and 1000', $e->getMessage());
            }
        }
    }

    public function test_query_is_immutable_and_refines_by_copy(): void
    {
        $base     = VectorQuery::nearest('docs', Vector::of([1, 2]), 5);
        $filtered = $base->where(VectorFilter::eq('a', 'x'))->where(VectorFilter::gt('n', 1));
        $exact    = $filtered->exact();
        $bare     = $exact->withoutPayload();

        self::assertSame([], $base->filters);
        self::assertCount(2, $filtered->filters);
        self::assertFalse($filtered->exact);
        self::assertTrue($exact->exact);
        self::assertTrue($filtered->includePayload);
        self::assertFalse($bare->includePayload);
        self::assertTrue($bare->exact, 'withoutPayload() must keep exact()');
        self::assertCount(2, $bare->filters, 'and the filters');
    }

    public function test_too_many_filters_are_refused(): void
    {
        $query   = VectorQuery::nearest('docs', Vector::of([1]), 1);
        $filters = array_fill(0, 17, VectorFilter::eq('a', 'x'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 16 filters/');

        $query->where(...$filters);
    }

    public function test_the_query_names_a_valid_collection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VectorQuery::nearest('Bad Name', Vector::of([1]), 1);
    }
}
