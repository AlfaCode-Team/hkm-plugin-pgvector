<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorCollection;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorMetric;
use PHPUnit\Framework\TestCase;
use Plugins\Database\Infrastructure\Drivers\PostgreSQLConfiguration;
use Plugins\Database\Infrastructure\Persistence\MultiDriverDatabaseAdapter;
use Plugins\Pgvector\Infrastructure\Config\PgvectorConfig;
use Plugins\Pgvector\Infrastructure\Persistence\PgvectorStore;

/**
 * Base for tests that need a REAL PostgreSQL with the vector extension.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * SKIPS, NEVER FAILS, WHEN THERE IS NO DATABASE — AND SAYS SO
 * ────────────────────────────────────────────────────────────────────────────
 * Set PGVECTOR_TEST_PORT (and optionally PGVECTOR_TEST_HOST, _DB, _USER,
 * _PASSWORD) to run these. The skip message is the honest report of what did not
 * run; a green Unit suite alone says nothing about the SQL.
 *
 * The connection is the Database plugin's real `MultiDriverDatabaseAdapter`, from
 * a sibling checkout (see tests/bootstrap.php), so the upsert compiler and the
 * binding path are the production ones.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * CLEANUP IS BY POSITIVE IDENTIFICATION
 * ────────────────────────────────────────────────────────────────────────────
 * Every collection a test makes is created through {@see self::collection()},
 * which records its name, and teardown drops exactly those. It never lists the
 * database and drops what "looks like" a test table: in this workspace deleting
 * by exclusion once destroyed real data. Names carry a random per-run prefix, so
 * two runs on one database cannot collide either.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected MultiDriverDatabaseAdapter $db;

    protected PgvectorStore $store;

    /** @var list<string> collections created by THIS test */
    private array $created = [];

    /** @var list<string> raw tables created by THIS test (foreign-table cases) */
    private array $rawTables = [];

    private string $runId = '';

    private bool $ready = false;

    protected function setUp(): void
    {
        $port = $_SERVER['PGVECTOR_TEST_PORT'] ?? getenv('PGVECTOR_TEST_PORT');

        if ($port === false || $port === null || $port === '') {
            self::markTestSkipped('PGVECTOR_TEST_PORT is not set: no PostgreSQL with pgvector to test against. These tests did NOT run.');
        }

        if (!class_exists(MultiDriverDatabaseAdapter::class)) {
            self::markTestSkipped('The sibling hkm-plugin-database checkout was not found, so the real DatabasePort adapter is unavailable.');
        }

        $this->db = self::connect((int) $port);

        try {
            $this->db->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('PostgreSQL is not reachable on the configured port: ' . strtok($e->getMessage(), "\n"));
        }

        $this->store = new PgvectorStore($this->db, PgvectorConfig::of());
        $this->runId = 'it' . bin2hex(random_bytes(3));
        $this->ready = true;
    }

    protected function tearDown(): void
    {
        // A skip in setUp() does not stop tearDown() — guard on a flag set at the END of setUp.
        if (!$this->ready) {
            return;
        }

        foreach ($this->created as $name) {
            try {
                $this->store->dropCollection($name);
            } catch (\Throwable) {
                // The test may have dropped it, or replaced the store; the name is ours either way.
                $this->db->execute('DROP TABLE IF EXISTS "hkm_vec_' . $name . '"');
            }
        }

        foreach ($this->rawTables as $table) {
            $this->db->execute('DROP TABLE IF EXISTS "' . $table . '"');
        }
    }

    protected static function connect(int $port): MultiDriverDatabaseAdapter
    {
        return new MultiDriverDatabaseAdapter(new PostgreSQLConfiguration(
            host:     (string) ($_SERVER['PGVECTOR_TEST_HOST'] ?? getenv('PGVECTOR_TEST_HOST') ?: '127.0.0.1'),
            port:     $port,
            database: (string) ($_SERVER['PGVECTOR_TEST_DB'] ?? getenv('PGVECTOR_TEST_DB') ?: 'postgres'),
            username: (string) ($_SERVER['PGVECTOR_TEST_USER'] ?? getenv('PGVECTOR_TEST_USER') ?: 'postgres'),
            password: (string) ($_SERVER['PGVECTOR_TEST_PASSWORD'] ?? getenv('PGVECTOR_TEST_PASSWORD') ?: ''),
            sslMode:  'disable',
        ));
    }

    /** A definition with a unique, tracked name; NOT yet created in the database. */
    protected function collection(string $label, int $dimensions, VectorMetric $metric = VectorMetric::Cosine): VectorCollection
    {
        $name = substr($this->runId . '_' . $label, 0, VectorCollection::MAX_NAME_LENGTH);
        $this->created[] = $name;

        return new VectorCollection($name, $dimensions, $metric);
    }

    /** Define, create and return a collection. */
    protected function created(string $label, int $dimensions, VectorMetric $metric = VectorMetric::Cosine): VectorCollection
    {
        $collection = $this->collection($label, $dimensions, $metric);
        $this->store->ensureCollection($collection);

        return $collection;
    }

    /** Register a raw table (made by the test itself) for positive cleanup. */
    protected function trackRawTable(string $table): void
    {
        $this->rawTables[] = $table;
    }

    /** @return list<string> */
    protected function planOf(string $sql, array $params = []): array
    {
        return array_map(static fn (array $r): string => (string) array_values($r)[0], $this->db->query('EXPLAIN (COSTS OFF) ' . $sql, $params));
    }
}
