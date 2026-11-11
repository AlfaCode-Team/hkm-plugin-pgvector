<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorOutcome;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorQuery;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorResult;
use PHPUnit\Framework\TestCase;

final class VectorResultTest extends TestCase
{
    public function test_only_a_conflict_is_unsatisfied(): void
    {
        foreach ([VectorOutcome::Created, VectorOutcome::Building, VectorOutcome::AlreadyExists] as $ok) {
            self::assertTrue($ok->matchesDefinition(), $ok->value);
        }

        self::assertFalse(VectorOutcome::Conflict->matchesDefinition());

        self::assertTrue(VectorResult::created('a')->isSatisfied());
        self::assertTrue(VectorResult::building('a')->isSatisfied());
        self::assertTrue(VectorResult::alreadyExists('a')->isSatisfied());
        self::assertFalse(VectorResult::conflict('a', 'm differs')->isSatisfied());
    }

    public function test_a_conflict_must_say_what_differs(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must explain what differs/');

        VectorResult::conflict('a', '');
    }

    public function test_describe_is_a_log_line(): void
    {
        self::assertSame('idx: created', VectorResult::created('idx')->describe());
        self::assertSame('idx: building (op-17)', VectorResult::building('idx', 'op-17')->describe());
        self::assertSame('idx: conflict (m is 8, wanted 16)', VectorResult::conflict('idx', 'm is 8, wanted 16')->describe());
    }

    public function test_where_keeps_exact_and_without_payload(): void
    {
        $q = VectorQuery::nearest('docs', Vector::of([1, 2]), 5)->exact()->withoutPayload();

        $filtered = $q->where(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter::eq('a', 'x'));

        self::assertTrue($filtered->exact, 'where() after exact() must stay exact');
        self::assertFalse($filtered->includePayload);
        self::assertSame(5, $filtered->k);
        self::assertSame('docs', $filtered->collection);
    }
}
