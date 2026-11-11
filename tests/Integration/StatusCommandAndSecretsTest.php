<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Integration;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\BufferIO;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\Vector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMetric;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorRecord;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorStoreException;
use Plugins\Database\Infrastructure\Drivers\PostgreSQLConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\Pgvector\Infrastructure\Cli\VectorStatusCommand;
use Plugins\Pgvector\Infrastructure\Config\PgvectorConfig;
use Plugins\Pgvector\Infrastructure\Persistence\PgvectorStore;
use Tests\Plugins\Pgvector\Support\IntegrationTestCase;

final class StatusCommandAndSecretsTest extends IntegrationTestCase
{
    /** @return array{code: int, output: string} */
    private function runStatus(array $tokens = []): array
    {
        $command = new VectorStatusCommand(fn (): PgvectorStore => $this->store);
        $io      = new BufferIO();

        ob_start();
        $code   = $command->execute($tokens, $io);
        $direct = (string) ob_get_clean();

        return ['code' => $code, 'output' => (string) preg_replace('/\e\[[0-9;]*m/', '', $direct . "\n" . $io->getOutput())];
    }

    public function test_status_lists_collections_sizes_and_indexes_and_runs_for_real(): void
    {
        $indexed   = $this->created('st_indexed', 4, VectorMetric::Euclidean);
        $unindexed = $this->created('st_plain', 3);

        $this->store->upsert($indexed->name, new VectorRecord('a', Vector::of([1, 2, 3, 4])));
        $this->store->upsert($indexed->name, new VectorRecord('b', Vector::of([4, 3, 2, 1])));
        $this->store->ensureIndex(new VectorIndex($indexed->name));

        $r = $this->runStatus();

        self::assertSame(AbstractCommand::SUCCESS, $r['code']);
        self::assertStringContainsString('extension version: ' . $this->store->extensionVersion(), $r['output']);
        self::assertStringContainsString("{$indexed->name} — 4 dimensions, euclidean, 2 rows", $r['output']);
        self::assertStringContainsString('vector_l2_ops', $r['output']);
        self::assertStringContainsString("{$unindexed->name} — 3 dimensions, cosine, 0 rows", $r['output']);
        self::assertStringContainsString('index: NONE — every search is a full scan', $r['output']);
    }

    public function test_require_index_turns_a_missing_index_into_a_failing_exit_code(): void
    {
        $this->created('st_gate', 3);

        self::assertSame(AbstractCommand::SUCCESS, $this->runStatus()['code']);

        $gated = $this->runStatus(['--require-index']);
        self::assertSame(AbstractCommand::FAILURE, $gated['code']);
        self::assertStringContainsString('without an HNSW index', $gated['output']);
    }

    public function test_status_reports_a_refusal_as_a_failure_not_a_stack_trace(): void
    {
        $noExt = $_SERVER['PGVECTOR_TEST_NOEXT_DB'] ?? getenv('PGVECTOR_TEST_NOEXT_DB');

        if (!\is_string($noExt) || $noExt === '') {
            self::markTestSkipped('PGVECTOR_TEST_NOEXT_DB is not set.');
        }

        $db      = new MultiDriverDatabaseAdapter(new PostgreSQLConfiguration(
            host: (string) ($_SERVER['PGVECTOR_TEST_HOST'] ?? '127.0.0.1'),
            port: (int) ($_SERVER['PGVECTOR_TEST_PORT'] ?? getenv('PGVECTOR_TEST_PORT')),
            database: $noExt,
            username: (string) ($_SERVER['PGVECTOR_TEST_USER'] ?? 'postgres'),
            password: (string) ($_SERVER['PGVECTOR_TEST_PASSWORD'] ?? ''),
            sslMode: 'disable',
        ));
        $command = new VectorStatusCommand(static fn (): PgvectorStore => new PgvectorStore($db, PgvectorConfig::of()));
        $io      = new BufferIO();

        ob_start();
        $code = $command->execute([], $io);
        ob_end_clean();

        self::assertSame(AbstractCommand::FAILURE, $code);
        self::assertStringContainsString('CREATE EXTENSION vector', $io->getOutput());
    }

    /**
     * describe(), and the message of a connection failure, must never carry a
     * credential. The store never sees one (the DatabasePort holds it), so this
     * pins the property rather than a mechanism: a future describe() that
     * helpfully included the DSN would fail here.
     */
    public function test_neither_describe_nor_a_connection_failure_leaks_the_password(): void
    {
        $secret = 'p4ssw0rd-never-print-me';

        $unreachable = new MultiDriverDatabaseAdapter(new PostgreSQLConfiguration(
            host: '127.0.0.1',
            port: 1,                      // nothing listens here
            database: 'whatever',
            username: 'someuser',
            password: $secret,
            sslMode: 'disable',
        ));
        $store = new PgvectorStore($unreachable, PgvectorConfig::of());

        self::assertStringNotContainsString($secret, $store->describe());
        self::assertStringNotContainsString('127.0.0.1', $store->describe());

        try {
            $store->collections();
            self::fail('port 1 must not connect');
        } catch (VectorStoreException $e) {
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString($secret, (string) $e->getPrevious()?->getMessage(), 'nor the wrapped driver message');
            self::assertSame('collections', $e->operation);
        }
    }
}
