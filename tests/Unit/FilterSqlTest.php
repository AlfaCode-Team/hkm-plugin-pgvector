<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter;
use PHPUnit\Framework\TestCase;
use Plugins\Pgvector\Infrastructure\Persistence\FilterSql;

final class FilterSqlTest extends TestCase
{
    public function test_no_filters_compile_to_nothing(): void
    {
        self::assertSame(['sql' => '', 'params' => []], FilterSql::compile([]));
    }

    public function test_eq_is_a_containment_with_the_value_as_a_json_parameter(): void
    {
        $c = FilterSql::compile([VectorFilter::eq('tenant', 't27')]);

        self::assertSame('payload @> CAST(:f0 AS jsonb)', $c['sql']);
        self::assertSame(['f0' => '{"tenant":"t27"}'], $c['params']);
    }

    public function test_eq_keeps_the_json_type_so_string_5_and_int_5_differ(): void
    {
        self::assertSame('{"k":5}', FilterSql::compile([VectorFilter::eq('k', 5)])['params']['f0']);
        self::assertSame('{"k":"5"}', FilterSql::compile([VectorFilter::eq('k', '5')])['params']['f0']);
        self::assertSame('{"k":true}', FilterSql::compile([VectorFilter::eq('k', true)])['params']['f0']);
        self::assertSame('{"k":false}', FilterSql::compile([VectorFilter::eq('k', false)])['params']['f0']);
    }

    public function test_in_is_an_or_of_containments_one_parameter_each(): void
    {
        $c = FilterSql::compile([VectorFilter::in('k', ['a', 7])]);

        self::assertSame('(payload @> CAST(:f0_0 AS jsonb) OR payload @> CAST(:f0_1 AS jsonb))', $c['sql']);
        self::assertSame(['f0_0' => '{"k":"a"}', 'f0_1' => '{"k":7}'], $c['params']);
    }

    public function test_a_range_guards_the_stored_type_and_compares_jsonb_to_jsonb(): void
    {
        $c = FilterSql::compile([VectorFilter::gte('price', 9.5)]);

        // The guard is what stops a boolean satisfying "> 5" (jsonb orders Boolean above Number),
        // and the jsonb-on-both-sides comparison is what stops a ::numeric cast throwing.
        self::assertSame(
            "(jsonb_typeof(payload -> CAST(:f0_field AS text)) = 'number' AND "
            . 'payload -> CAST(:f0_field AS text) >= CAST(:f0_value AS jsonb))',
            $c['sql'],
        );
        self::assertSame(['f0_field' => 'price', 'f0_value' => '9.5'], $c['params']);
    }

    public function test_every_comparator_is_distinct(): void
    {
        $ops = [];

        foreach ([
            VectorFilter::gt('n', 1), VectorFilter::gte('n', 1), VectorFilter::lt('n', 1), VectorFilter::lte('n', 1),
        ] as $filter) {
            preg_match('/ (>=|<=|>|<) CAST/', FilterSql::compile([$filter])['sql'], $m);
            $ops[] = $m[1];
        }

        self::assertSame(['>', '>=', '<', '<='], $ops);
    }

    public function test_a_whole_number_float_keeps_its_fraction_in_the_bound(): void
    {
        self::assertSame('5.0', FilterSql::compile([VectorFilter::gt('n', 5.0)])['params']['f0_value']);
        self::assertSame('5', FilterSql::compile([VectorFilter::gt('n', 5)])['params']['f0_value']);
    }

    public function test_filters_are_anded_with_unique_placeholders(): void
    {
        $c = FilterSql::compile([
            VectorFilter::eq('a', 'x'),
            VectorFilter::gt('n', 1),
            VectorFilter::lt('n', 9),
        ]);

        self::assertSame(
            "payload @> CAST(:f0 AS jsonb) AND (jsonb_typeof(payload -> CAST(:f1_field AS text)) = 'number' AND "
            . 'payload -> CAST(:f1_field AS text) > CAST(:f1_value AS jsonb)) AND '
            . "(jsonb_typeof(payload -> CAST(:f2_field AS text)) = 'number' AND "
            . 'payload -> CAST(:f2_field AS text) < CAST(:f2_value AS jsonb))',
            $c['sql'],
        );
    }

    /**
     * PDO treats every `?` as a positional placeholder — INCLUDING the one in
     * jsonb's `?`/`?|`/`?&` operators — so none may appear. And the placeholders
     * in the SQL must be exactly the keys in the parameter array: an unbound
     * placeholder is a runtime failure, and an unused parameter makes PDO refuse
     * the statement ("number of bound variables does not match").
     */
    public function test_no_question_mark_and_placeholders_match_the_parameters_exactly(): void
    {
        $c = FilterSql::compile([
            VectorFilter::eq('a', 'x?'),
            VectorFilter::in('b', ['y', 'z']),
            VectorFilter::gt('c', 1),
            VectorFilter::lte('c', 5),
        ]);

        self::assertStringNotContainsString('?', $c['sql']);

        preg_match_all('/:([a-z0-9_]+)/', $c['sql'], $m);
        $inSql = array_values(array_unique($m[1]));
        $keys  = array_keys($c['params']);
        sort($inSql);
        sort($keys);

        self::assertSame($keys, $inSql);
    }

    public function test_a_hostile_value_stays_in_the_parameters_and_out_of_the_sql(): void
    {
        $evil = "'); DROP TABLE hkm_vec_x; --";
        $c    = FilterSql::compile([VectorFilter::eq('k', $evil), VectorFilter::in('j', [$evil])]);

        self::assertStringNotContainsString('DROP', $c['sql']);
        self::assertStringContainsString('DROP TABLE', implode('', $c['params']));
        self::assertSame(['k' => $evil], json_decode($c['params']['f0'], true));
    }
}
