<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Integration;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMatch;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorQuery;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Plugins\Pgvector\Support\IntegrationTestCase;

/**
 * The SQL held to the port's filter rules, two ways:
 *
 *   1. against HAND-WRITTEN expectations (the column below), and
 *   2. against {@see VectorFilter::matches()}, the executable specification.
 *
 * The dataset is full of the traps the rules exist for: a missing field, the
 * string "5" next to the integer 5, a boolean in a numeric field, a non-numeric
 * string a `::numeric` cast would choke on, and a float equal to an integer.
 */
final class FilterSemanticsTest extends IntegrationTestCase
{
    /** @var array<string, array<string, bool|int|float|string>> */
    private const PAYLOADS = [
        'r1' => ['tenant' => 'a', 'n' => 5, 'flag' => true, 'price' => 9.5],
        'r2' => ['tenant' => 'b', 'n' => '5'],
        'r3' => ['tenant' => 'a', 'n' => 5.0],
        'r4' => ['tenant' => 'a', 'n' => true],
        'r5' => ['tenant' => 'c'],
        'r6' => ['tenant' => 'a', 'n' => 'abc'],
        'r7' => ['tenant' => 'a', 'n' => -3, 'flag' => false],
        'r8' => ['tenant' => 'b', 'n' => 100, 'flag' => true],
        'r9' => ['tenant' => 'Ab'],
    ];

    /** @return iterable<string, array{list<VectorFilter>, list<string>}> */
    public static function cases(): iterable
    {
        yield 'eq string'                 => [[VectorFilter::eq('tenant', 'a')], ['r1', 'r3', 'r4', 'r6', 'r7']];
        yield 'eq is case sensitive'      => [[VectorFilter::eq('tenant', 'ab')], []];
        yield 'eq int matches float 5.0'  => [[VectorFilter::eq('n', 5)], ['r1', 'r3']];
        yield 'eq string "5" only string' => [[VectorFilter::eq('n', '5')], ['r2']];
        yield 'eq true is not 1'          => [[VectorFilter::eq('n', true)], ['r4']];
        yield 'eq bool true'              => [[VectorFilter::eq('flag', true)], ['r1', 'r8']];
        yield 'eq bool false'             => [[VectorFilter::eq('flag', false)], ['r7']];
        yield 'eq on a field nobody has'  => [[VectorFilter::eq('zzz', 'x')], []];
        yield 'in strings'                => [[VectorFilter::in('tenant', ['a', 'c'])], ['r1', 'r3', 'r4', 'r5', 'r6', 'r7']];
        yield 'in mixed types'            => [[VectorFilter::in('n', [5, '5'])], ['r1', 'r2', 'r3']];
        yield 'gt skips bool, string, missing, and does not throw on "abc"' => [[VectorFilter::gt('n', 4)], ['r1', 'r3', 'r8']];
        yield 'gte'                       => [[VectorFilter::gte('n', 5)], ['r1', 'r3', 'r8']];
        yield 'lt'                        => [[VectorFilter::lt('n', 5)], ['r7']];
        yield 'lte with float bound'      => [[VectorFilter::lte('n', 5.0)], ['r1', 'r3', 'r7']];
        yield 'gt on a float field'       => [[VectorFilter::gt('price', 9)], ['r1']];
        yield 'gt on a field nobody has'  => [[VectorFilter::gt('zzz', 0)], []];
        yield 'and of eq and range'       => [[VectorFilter::eq('tenant', 'a'), VectorFilter::gt('n', 0)], ['r1', 'r3']];
        yield 'two ranges on one field'   => [[VectorFilter::gt('n', 0), VectorFilter::lt('n', 50)], ['r1', 'r3']];
        yield 'negative bound'            => [[VectorFilter::lt('n', -1)], ['r7']];
    }

    /**
     * @param list<VectorFilter> $filters
     * @param list<string>       $expected
     */
    #[DataProvider('cases')]
    public function test_filters_match_the_hand_written_expectation_and_the_specification(array $filters, array $expected): void
    {
        $c = $this->dataset();

        // 1. The specification agrees with the hand-written column (so the column is not itself wrong).
        $spec = [];

        foreach (self::PAYLOADS as $id => $payload) {
            $all = true;

            foreach ($filters as $f) {
                $all = $all && $f->matches($payload);
            }

            if ($all) {
                $spec[] = $id;
            }
        }

        self::assertSame($expected, $spec, 'VectorFilter::matches() disagrees with the hand-written expectation');

        // 2. The SQL agrees — through count(), through an exact search, and through the default search.
        self::assertSame(\count($expected), $this->store->count($c, $filters));

        foreach ([true, false] as $exact) {
            $query = VectorQuery::nearest($c, Vector::of([1, 1]), 20)->where(...$filters);
            $ids   = array_map(static fn (VectorMatch $m): string => $m->id, $this->store->search($exact ? $query->exact() : $query));
            sort($ids);

            self::assertSame($expected, $ids, 'search exact=' . (int) $exact);
        }
    }

    public function test_an_in_filter_at_the_100_value_ceiling_runs(): void
    {
        $c      = $this->dataset();
        $values = array_map(static fn (int $i): string => "t{$i}", range(1, 99));
        $values[] = 'a';

        self::assertSame(5, $this->store->count($c, [VectorFilter::in('tenant', $values)]));
    }

    public function test_the_index_does_not_change_any_filter_answer(): void
    {
        $c = $this->dataset();
        $this->store->ensureIndex(new VectorIndex($c));

        self::assertSame(2, $this->store->count($c, [VectorFilter::eq('n', 5)]));
        $ids = array_map(
            static fn (VectorMatch $m): string => $m->id,
            $this->store->search(VectorQuery::nearest($c, Vector::of([1, 1]), 20)->where(VectorFilter::gt('n', 4))),
        );
        sort($ids);
        self::assertSame(['r1', 'r3', 'r8'], $ids);
    }

    private function dataset(): string
    {
        $c = $this->created('filters', 2);
        $i = 0;

        foreach (self::PAYLOADS as $id => $payload) {
            $i++;
            $this->store->upsert($c->name, new VectorRecord($id, Vector::of([1, $i]), $payload));
        }

        return $c->name;
    }
}
