<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * These test the KERNEL's value objects. They live in this plugin because the
 * kernel repository was not touched beyond the port files; if the kernel grows a
 * test directory for them, move them.
 */
final class VectorTest extends TestCase
{
    public function test_it_normalises_integers_to_floats_and_keeps_order(): void
    {
        $v = Vector::of([1, 2.5, -3]);

        self::assertSame([1.0, 2.5, -3.0], $v->toArray());
        self::assertSame(3, $v->dimensions());
    }

    /** @return iterable<string, array{array<int|string, mixed>, string}> */
    public static function refused(): iterable
    {
        yield 'empty'              => [[], 'at least one dimension'];
        yield 'string keys'        => [['a' => 1.0, 'b' => 2.0], 'must be a list'];
        yield 'gapped keys'        => [[0 => 1.0, 2 => 2.0], 'must be a list'];
        yield 'NaN'                => [[1.0, NAN], 'not finite'];
        yield 'infinity'           => [[1.0, INF], 'not finite'];
        yield 'negative infinity'  => [[-INF], 'not finite'];
        yield 'numeric string'     => [[1.0, '0.5'], 'not a number'];
        yield 'bool'               => [[true], 'not a number'];
        yield 'null'               => [[null], 'not a number'];
        yield 'nested'             => [[[1.0]], 'not a number'];
    }

    /** @param array<int|string, mixed> $input */
    #[DataProvider('refused')]
    public function test_it_refuses_what_no_store_can_index(array $input, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($reason, '/') . '/');

        Vector::of($input);
    }

    public function test_2000_dimensions_is_accepted_and_2001_is_refused_for_the_measured_hnsw_limit(): void
    {
        self::assertSame(2000, Vector::of(array_fill(0, 2000, 0.5))->dimensions());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 2000 dimensions.*has 2001/s');

        Vector::of(array_fill(0, 2001, 0.5));
    }

    public function test_zero_detection(): void
    {
        self::assertTrue(Vector::of([0, 0.0, -0.0])->isZero());
        self::assertFalse(Vector::of([0, 0, 1e-300])->isZero());
    }
}
