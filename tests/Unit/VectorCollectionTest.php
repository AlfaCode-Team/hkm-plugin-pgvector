<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\DimensionMismatchException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorCollection;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMetric;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VectorCollectionTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'empty'         => [''];
        yield 'upper case'    => ['Docs'];
        yield 'hyphen'        => ['my-docs'];
        yield 'dot'           => ['my.docs'];
        yield 'slash'         => ['a/b'];
        yield 'leading digit' => ['1docs'];
        yield 'leading _'     => ['_docs'];
        yield 'space'         => ['my docs'];
        yield 'quote'         => ['a"b'];
        yield 'semicolon'     => ['a;drop table x'];
        yield 'trailing LF'   => ["docs\n"];
        yield '49 chars'      => ['a' . str_repeat('b', 48)];
    }

    #[DataProvider('badNames')]
    public function test_names_that_are_not_a_bare_identifier_are_refused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/collection name must match/');

        new VectorCollection($name, 3);
    }

    public function test_a_48_character_name_is_accepted(): void
    {
        $name = 'a' . str_repeat('b', 47);

        self::assertSame($name, (new VectorCollection($name, 3))->name);
    }

    public function test_dimensions_are_bounded_by_what_can_be_indexed(): void
    {
        self::assertSame(2000, (new VectorCollection('x', 2000))->dimensions);

        foreach ([0, -1, 2001] as $bad) {
            try {
                new VectorCollection('x', $bad);
                self::fail("{$bad} dimensions should be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('between 1 and 2000', $e->getMessage());
            }
        }
    }

    public function test_a_wrong_length_vector_is_refused_with_both_numbers(): void
    {
        $collection = new VectorCollection('docs', 3);

        try {
            $collection->assertAccepts(Vector::of([1, 2]));
            self::fail('a 2-dimension vector must not be accepted by a 3-dimension collection');
        } catch (DimensionMismatchException $e) {
            self::assertSame('docs', $e->collection);
            self::assertSame(3, $e->expected);
            self::assertSame(2, $e->actual);
            self::assertStringContainsString('re-embed', $e->getMessage());
        }
    }

    public function test_the_zero_vector_is_refused_for_cosine_only(): void
    {
        $zero = Vector::of([0, 0, 0]);

        foreach ([VectorMetric::Euclidean, VectorMetric::DotProduct] as $metric) {
            (new VectorCollection('x', 3, $metric))->assertAccepts($zero);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cosine distance, which is undefined for the zero vector/');

        (new VectorCollection('x', 3, VectorMetric::Cosine))->assertAccepts($zero);
    }

    public function test_equality_is_by_value_across_all_three_fields(): void
    {
        $base = new VectorCollection('x', 3, VectorMetric::Cosine);

        self::assertTrue($base->equals(new VectorCollection('x', 3, VectorMetric::Cosine)));
        self::assertFalse($base->equals(new VectorCollection('y', 3, VectorMetric::Cosine)));
        self::assertFalse($base->equals(new VectorCollection('x', 4, VectorMetric::Cosine)));
        self::assertFalse($base->equals(new VectorCollection('x', 3, VectorMetric::Euclidean)));
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function badIndexes(): iterable
    {
        yield 'm below 2'                  => [1, 64, 'm must be between 2 and 100'];
        yield 'm above 100'                => [101, 400, 'm must be between 2 and 100'];
        yield 'ef below 4'                 => [2, 3, 'efConstruction must be between 4 and 1000'];
        yield 'ef above 1000'              => [16, 1001, 'efConstruction must be between 4 and 1000'];
        // 8 is in range, 16 is in range — the COMBINATION is what pgvector refuses.
        yield 'ef in range but < 2 * m'    => [16, 8, 'at least 2 * m (32)'];
        yield 'ef 31 with m 16'            => [16, 31, 'at least 2 * m (32)'];
    }

    #[DataProvider('badIndexes')]
    public function test_index_parameters_are_checked_against_the_measured_bounds(int $m, int $ef, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($reason, '/') . '/');

        new VectorIndex('x', $m, $ef);
    }

    public function test_index_boundaries_are_accepted(): void
    {
        self::assertSame(32, (new VectorIndex('x', 16, 32))->efConstruction);
        self::assertSame(2, (new VectorIndex('x', 2, 4))->m);
        self::assertSame(100, (new VectorIndex('x', 100, 200))->m);
        self::assertSame(1000, (new VectorIndex('x', 16, 1000))->efConstruction);
    }
}
