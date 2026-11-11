<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMetric;

/**
 * Brute-force nearest neighbours in plain PHP: the oracle the SQL is held to.
 *
 * It shares no code with the store. Distances use the conventions the port
 * documents (lower is closer; dot product is negated) and are computed in
 * float64, so against pgvector's float32 storage a tolerance is needed — callers
 * compare ids exactly only where the data has no near-ties.
 */
final class Brute
{
    /**
     * @param list<float|int> $a
     * @param list<float|int> $b
     */
    public static function distance(VectorMetric $metric, array $a, array $b): float
    {
        $dot = 0.0;
        $na  = 0.0;
        $nb  = 0.0;
        $l2  = 0.0;

        foreach ($a as $i => $x) {
            $dot += $x * $b[$i];
            $na  += $x * $x;
            $nb  += $b[$i] * $b[$i];
            $l2  += ($x - $b[$i]) ** 2;
        }

        return match ($metric) {
            VectorMetric::Cosine     => 1.0 - $dot / (sqrt($na) * sqrt($nb)),
            VectorMetric::Euclidean  => sqrt($l2),
            VectorMetric::DotProduct => -$dot,
        };
    }

    /**
     * @param array<string, list<float|int>> $vectors id => vector
     * @param list<string>|null              $only    restrict to these ids (the filter's matches)
     * @return list<string> ids, closest first, ties by id (bytewise)
     */
    public static function nearest(VectorMetric $metric, array $vectors, array $query, int $k, ?array $only = null): array
    {
        $rows = [];

        foreach ($vectors as $id => $v) {
            $id = (string) $id;

            if ($only !== null && !\in_array($id, $only, true)) {
                continue;
            }

            $rows[] = [self::distance($metric, $v, $query), $id];
        }

        usort($rows, static fn (array $x, array $y): int => [$x[0], $x[1]] <=> [$y[0], $y[1]]);

        return array_map(static fn (array $r): string => $r[1], \array_slice($rows, 0, $k));
    }
}
