<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/**
 * A DatabasePort that delegates to a real one and fails the Nth upsert().
 *
 * The only honest way to test "all or nothing" against a real database: a
 * validation error is refused before any write, so a failure MID-batch has to
 * come from the database side. The delegate is real, so what was rolled back is
 * observable in the table.
 */
final class FailingDatabase implements DatabasePort
{
    private int $upserts = 0;

    public function __construct(private readonly DatabasePort $inner, private readonly int $failOnUpsert) {}

    public function query(string $sql, array $params = []): array
    {
        return $this->inner->query($sql, $params);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->inner->queryOne($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->inner->execute($sql, $params);
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        if (++$this->upserts === $this->failOnUpsert) {
            throw new \PDOException('SQLSTATE[HY000]: simulated mid-batch failure');
        }

        return $this->inner->upsert($table, $values, $conflictColumns, $updateColumns);
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return $this->inner->lastInsertId($sequence);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }
}
