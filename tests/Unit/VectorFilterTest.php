<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * {@see VectorFilter::matches()} is the specification. Every row below is a
 * verdict written by hand from the rules in the class, never computed — and the
 * Integration suite then holds the SQL to the same table.
 */
final class VectorFilterTest extends TestCase
{
    /**
     * @return iterable<string, array{VectorFilter, array<string, bool|int|float|string>, bool}>
     */
    public static function verdicts(): iterable
    {
        // Rule 1: a missing field never matches — for EVERY operator.
        yield 'eq on missing'      => [VectorFilter::eq('k', 'x'), [], false];
        yield 'in on missing'      => [VectorFilter::in('k', ['x']), ['other' => 'x'], false];
        yield 'gt on missing'      => [VectorFilter::gt('k', 0), [], false];
        yield 'lte on missing'     => [VectorFilter::lte('k', 100), [], false];

        // Rule 2: types are strict, int and float are one numeric type.
        yield 'string 5 vs int 5'  => [VectorFilter::eq('k', 5), ['k' => '5'], false];
        yield 'int 5 vs string 5'  => [VectorFilter::eq('k', '5'), ['k' => 5], false];
        yield 'true vs 1'          => [VectorFilter::eq('k', 1), ['k' => true], false];
        yield '1 vs true'          => [VectorFilter::eq('k', true), ['k' => 1], false];
        yield 'int vs same float'  => [VectorFilter::eq('k', 5), ['k' => 5.0], true];
        yield 'true vs true'       => [VectorFilter::eq('k', true), ['k' => true], true];
        yield 'false vs false'     => [VectorFilter::eq('k', false), ['k' => false], true];
        yield 'string equal'       => [VectorFilter::eq('k', 'a'), ['k' => 'a'], true];
        yield 'string is case sensitive' => [VectorFilter::eq('k', 'a'), ['k' => 'A'], false];

        yield 'in hit'             => [VectorFilter::in('k', ['a', 'b']), ['k' => 'b'], true];
        yield 'in miss'            => [VectorFilter::in('k', ['a', 'b']), ['k' => 'c'], false];
        yield 'in mixed types'     => [VectorFilter::in('k', ['5', 6]), ['k' => 5], false];
        yield 'in int'             => [VectorFilter::in('k', ['5', 6]), ['k' => 6], true];

        // Rule 3: ranges are numeric only and never match a non-number.
        yield 'gt true'            => [VectorFilter::gt('k', 5), ['k' => 6], true];
        yield 'gt equal'           => [VectorFilter::gt('k', 5), ['k' => 5], false];
        yield 'gte equal'          => [VectorFilter::gte('k', 5), ['k' => 5], true];
        yield 'lt true'            => [VectorFilter::lt('k', 5.5), ['k' => 5], true];
        yield 'lte float edge'     => [VectorFilter::lte('k', 5.5), ['k' => 5.5], true];
        yield 'bool is not > 5'    => [VectorFilter::gt('k', 5), ['k' => true], false];
        yield 'string is not > 5'  => [VectorFilter::gt('k', 5), ['k' => 'abc'], false];
        yield 'numeric string is not a number' => [VectorFilter::gte('k', 5), ['k' => '9'], false];
        yield 'negative bound'     => [VectorFilter::gt('k', -1), ['k' => 0], true];
    }

    /** @param array<string, bool|int|float|string> $payload */
    #[DataProvider('verdicts')]
    public function test_the_specification(VectorFilter $filter, array $payload, bool $expected): void
    {
        self::assertSame($expected, $filter->matches($payload));
    }

    /** @return iterable<string, array{callable(): mixed, string}> */
    public static function refused(): iterable
    {
        yield 'dotted field'      => [static fn () => VectorFilter::eq('a.b', 'x'), 'payload field name must match'];
        yield 'empty field'       => [static fn () => VectorFilter::eq('', 'x'), 'payload field name must match'];
        yield 'leading digit'     => [static fn () => VectorFilter::eq('1a', 'x'), 'payload field name must match'];
        yield 'field with quote'  => [static fn () => VectorFilter::eq("a'b", 'x'), 'payload field name must match'];
        yield 'field 65 chars'    => [static fn () => VectorFilter::eq(str_repeat('a', 65), 'x'), 'payload field name must match'];
        yield 'empty In'          => [static fn () => VectorFilter::in('k', []), 'non-empty list'];
        yield 'In with float'     => [static fn () => VectorFilter::in('k', [1.5]), 'strings and integers only'];
        yield 'In with bool'      => [static fn () => VectorFilter::in('k', [true]), 'strings and integers only'];
        yield 'In keyed'          => [static fn () => VectorFilter::in('k', ['a' => 'x']), 'non-empty list'];
        yield 'In over 100'       => [static fn () => VectorFilter::in('k', range(1, 101)), 'at most 100 values'];
        yield 'NaN bound'         => [static fn () => VectorFilter::gt('k', NAN), 'must be finite'];
        yield 'INF bound'         => [static fn () => VectorFilter::lt('k', INF), 'must be finite'];
    }

    /** @param callable(): mixed $make */
    #[DataProvider('refused')]
    public function test_what_no_store_can_run_is_refused_at_construction(callable $make, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($reason, '/') . '/');

        $make();
    }

    /**
     * Float equality and string ranges are unexpressible, not merely refused at
     * runtime: the signatures do not take them. The test pins that, so widening
     * a signature to accept them is a deliberate act with a failing test.
     */
    public function test_float_equality_and_string_ranges_do_not_type_check(): void
    {
        $eq = new \ReflectionMethod(VectorFilter::class, 'eq');
        $gt = new \ReflectionMethod(VectorFilter::class, 'gt');

        self::assertSame('string|int|bool', (string) $eq->getParameters()[1]->getType());
        self::assertSame('int|float', (string) $gt->getParameters()[1]->getType());
    }
}
