<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/** A DatabasePort that delegates to a real one and records every SQL string it was given. */
final class RecordingDatabase implements DatabasePort
{
    /** @var list<string> */
    public array $sql = [];

    public function __construct(private readonly DatabasePort $inner) {}

    public function query(string $sql, array $params = []): array
    {
        $this->sql[] = $sql;

        return $this->inner->query($sql, $params);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $this->sql[] = $sql;

        return $this->inner->queryOne($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->sql[] = $sql;

        return $this->inner->execute($sql, $params);
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        $this->sql[] = "UPSERT {$table}";

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

    /** How many nearest-neighbour passes ran. */
    public function searchPasses(): int
    {
        return \count(array_filter($this->sql, static fn (string $s): bool => str_starts_with($s, 'SELECT id, d, payload FROM')));
    }
}
