<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\Pgvector\Infrastructure\Config\PgvectorConfig;

final class PgvectorConfigTest extends TestCase
{
    public function test_ef_search_is_clamped_to_the_range_pgvector_accepts(): void
    {
        self::assertSame(1, PgvectorConfig::of(efSearch: 0)->efSearch);
        self::assertSame(1, PgvectorConfig::of(efSearch: -50)->efSearch);
        self::assertSame(1000, PgvectorConfig::of(efSearch: 5000)->efSearch);
        self::assertSame(64, PgvectorConfig::of()->efSearch);
    }

    public function test_an_unknown_iterative_mode_falls_back_to_relaxed_order(): void
    {
        self::assertSame('relaxed_order', PgvectorConfig::of(iterativeScan: 'strict')->iterativeScan);
        self::assertSame('relaxed_order', PgvectorConfig::of(iterativeScan: '')->iterativeScan);
        self::assertSame('strict_order', PgvectorConfig::of(iterativeScan: 'strict_order')->iterativeScan);
        self::assertSame('off', PgvectorConfig::of(iterativeScan: 'off')->iterativeScan);
    }

    public function test_max_scan_tuples_cannot_be_zero_or_negative(): void
    {
        self::assertSame(1, PgvectorConfig::of(maxScanTuples: 0)->maxScanTuples);
        self::assertSame(1, PgvectorConfig::of(maxScanTuples: -1)->maxScanTuples);
    }

    public function test_from_env_reads_the_declared_keys_through_env_not_getenv(): void
    {
        $saved = [$_ENV['PGVECTOR_EF_SEARCH'] ?? null, $_ENV['PGVECTOR_ITERATIVE_SCAN'] ?? null];

        try {
            $_ENV['PGVECTOR_EF_SEARCH']      = '200';
            $_ENV['PGVECTOR_ITERATIVE_SCAN'] = 'strict_order';

            $config = PgvectorConfig::fromEnv();

            self::assertSame(200, $config->efSearch);
            self::assertSame('strict_order', $config->iterativeScan);
        } finally {
            foreach (['PGVECTOR_EF_SEARCH', 'PGVECTOR_ITERATIVE_SCAN'] as $i => $key) {
                if ($saved[$i] === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $saved[$i];
                }
            }
        }
    }
}
