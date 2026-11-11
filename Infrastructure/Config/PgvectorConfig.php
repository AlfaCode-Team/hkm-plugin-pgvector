<?php

declare(strict_types=1);

namespace Plugins\Pgvector\Infrastructure\Config;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorQuery;

/**
 * Search-time knobs, normalised in the private constructor so neither named
 * constructor can bypass it.
 *
 * `env()` and not `getenv()`: `$_ENV` is the source of truth in this framework
 * and `putenv()` is never called.
 *
 * None of these can break the port's guarantee that a search returns
 * min(k, matches): a pass that comes up short is repeated exactly. They change
 * how often that second, expensive pass is needed — and recall on the pass
 * that does not need it.
 */
final readonly class PgvectorConfig
{
    public const ITERATIVE_MODES = ['off', 'relaxed_order', 'strict_order'];

    /** The floor for hnsw.ef_search; the effective value is max(this, k). */
    public int $efSearch;

    /**
     * off | relaxed_order | strict_order.
     *
     * Measured (pgvector 0.8.7, 30,000 vectors, 2% filter, k = 10): `off`
     * returned 2 rows, `relaxed_order` 10, `strict_order` 10. relaxed_order
     * may return neighbours slightly out of order, which the outer sort
     * repairs for the rows it returns; strict_order is exact in order but, in
     * the one run measured, missed one of the ten true neighbours — HNSW is
     * approximate either way.
     */
    public string $iterativeScan;

    /** hnsw.max_scan_tuples — how far an iterative scan goes before giving up. */
    public int $maxScanTuples;

    private function __construct(int $efSearch, string $iterativeScan, int $maxScanTuples)
    {
        // 1..1000 is the range pgvector itself accepts for hnsw.ef_search.
        $this->efSearch      = max(1, min(VectorQuery::MAX_K, $efSearch));
        $this->iterativeScan = \in_array($iterativeScan, self::ITERATIVE_MODES, true)
            ? $iterativeScan
            : 'relaxed_order';
        $this->maxScanTuples = max(1, $maxScanTuples);
    }

    public static function fromEnv(): self
    {
        return new self(
            efSearch:      (int) env('PGVECTOR_EF_SEARCH', 64),
            iterativeScan: (string) env('PGVECTOR_ITERATIVE_SCAN', 'relaxed_order'),
            maxScanTuples: (int) env('PGVECTOR_MAX_SCAN_TUPLES', 20000),
        );
    }

    public static function of(int $efSearch = 64, string $iterativeScan = 'relaxed_order', int $maxScanTuples = 20000): self
    {
        return new self($efSearch, $iterativeScan, $maxScanTuples);
    }
}
