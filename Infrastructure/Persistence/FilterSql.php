<?php

declare(strict_types=1);

namespace Plugins\Pgvector\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorFilterOperator;

/**
 * Compiles {@see VectorFilter}s to a PostgreSQL WHERE fragment over a jsonb
 * `payload` column. Pure: no database, so it is tested directly.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY CONTAINMENT FOR EQUALITY, AND A GUARDED jsonb COMPARISON FOR RANGES
 * ────────────────────────────────────────────────────────────────────────────
 * Eq and In use `payload @> '{"field": value}'`. Measured on PostgreSQL 18.6,
 * containment has exactly the semantics the port specifies — a missing field is
 * false, `"5"` does not contain 5, `true` does not contain 1, and 5 does contain
 * 5.0 — and it is the one operator the GIN `jsonb_path_ops` index serves, which
 * is what turned a 2%-selective filter from a wrong answer (2 rows of 10) into a
 * bitmap scan with an exact sort (10 of 10).
 *
 * Ranges cannot use containment, and the two obvious spellings are both wrong:
 *   (payload->>'k')::numeric > 5   throws for the whole query the moment one
 *                                  row holds a non-numeric string in `k`;
 *   payload->'k' > '5'::jsonb      orders by TYPE first, and a boolean, array or
 *                                  object compares GREATER than any number.
 * So a range is `jsonb_typeof(payload->'k') = 'number' AND payload->'k' > 5`
 * with jsonb on both sides: the comparison cannot throw, and the guard removes
 * the types that would compare wrongly.
 *
 * Every value reaches the database as a bound parameter, and the field name of a
 * range as one too (`payload -> CAST(:p AS text)`), so no part of a filter is
 * ever interpolated. Placeholders are unique per FILTER; the two reads of a
 * range's field name within one filter share a placeholder, which pdo_pgsql
 * accepts with native prepares and with emulation (measured on PHP 8.5.12 — PHP's
 * manual warns that a repeated named parameter is unsupported on some drivers,
 * and this plugin is PostgreSQL-only, so only this driver matters).
 *
 * No `?` appears in the output: PDO reads every `?` as a positional
 * placeholder, including inside jsonb's `?` and `?|` operators, which is why
 * those are not used.
 */
final class FilterSql
{
    private function __construct() {}

    /**
     * @param list<VectorFilter> $filters
     * @return array{sql: string, params: array<string, mixed>} `sql` is '' when there are no filters,
     *         otherwise a fragment to AND into a WHERE
     */
    public static function compile(array $filters, string $prefix = 'f', string $column = 'payload'): array
    {
        $parts  = [];
        $params = [];

        foreach (array_values($filters) as $i => $filter) {
            $n = $prefix . $i;

            switch ($filter->operator) {
                case VectorFilterOperator::Eq:
                    $parts[]        = "{$column} @> CAST(:{$n} AS jsonb)";
                    $params[$n]     = self::object($filter->field, $filter->value);
                    break;

                case VectorFilterOperator::In:
                    $ors = [];
                    foreach ((array) $filter->value as $j => $candidate) {
                        $key          = "{$n}_{$j}";
                        $ors[]        = "{$column} @> CAST(:{$key} AS jsonb)";
                        $params[$key] = self::object($filter->field, $candidate);
                    }
                    $parts[] = '(' . implode(' OR ', $ors) . ')';
                    break;

                default:
                    $field          = "{$n}_field";
                    $value          = "{$n}_value";
                    $parts[]        = "(jsonb_typeof({$column} -> CAST(:{$field} AS text)) = 'number' AND "
                        . "{$column} -> CAST(:{$field} AS text) " . self::comparator($filter->operator)
                        . " CAST(:{$value} AS jsonb))";
                    $params[$field] = $filter->field;
                    $params[$value] = self::number($filter->value);
            }
        }

        return ['sql' => implode(' AND ', $parts), 'params' => $params];
    }

    private static function comparator(VectorFilterOperator $op): string
    {
        return match ($op) {
            VectorFilterOperator::Gt  => '>',
            VectorFilterOperator::Gte => '>=',
            VectorFilterOperator::Lt  => '<',
            VectorFilterOperator::Lte => '<=',
            default => throw new \LogicException('Not a range operator: ' . $op->value),
        };
    }

    private static function object(string $field, mixed $value): string
    {
        return json_encode([$field => $value], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function number(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
