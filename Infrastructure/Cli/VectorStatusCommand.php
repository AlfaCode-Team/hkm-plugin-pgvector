<?php

declare(strict_types=1);

namespace Plugins\Pgvector\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorStoreException;
use Plugins\Pgvector\Infrastructure\Persistence\PgvectorStore;

/**
 * `vector:status` — what pgvector is installed, which collections exist, and
 * what is indexed.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHAT IT IS FOR
 * ────────────────────────────────────────────────────────────────────────────
 * The failure it exists to catch is a collection with NO HNSW index: every
 * search then works, returns the right answer, and scans the whole table. Nothing
 * errors and nothing is wrong until the table is large, at which point it is
 * merely slow in a way that looks like the database. The command prints
 * `index: NONE` in that case, and exits non-zero with --require-index so a
 * deploy can gate on it.
 *
 * It reads only the catalog and `count(*)`. It never creates, alters or drops
 * anything, and prints no host, DSN or credential — the store does not know them.
 */
final class VectorStatusCommand extends AbstractCommand
{
    /** @param \Closure(): PgvectorStore $store built lazily, so listing commands never opens a connection */
    public function __construct(private readonly \Closure $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'vector:status';
        $this->description = 'Show the pgvector version, every vector collection, its size and its index.';

        $this->addOption(
            'require-index',
            '',
            'Exit non-zero when any collection has no HNSW index (a deploy gate).',
        );
    }

    protected function handle(): int
    {
        try {
            $store       = ($this->store)();
            $version     = $store->extensionVersion();
            $collections = $store->collections();
        } catch (VectorStoreException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->section('pgvector');
        $this->info('extension version: ' . $version);

        if ($collections === []) {
            $this->muted('No vector collections.');

            return self::SUCCESS;
        }

        $unindexed = 0;

        foreach ($collections as $collection) {
            $indexes = $store->indexDefinitions($collection->name);
            $rows    = $store->count($collection->name);

            $this->newLine();
            $this->info(\sprintf(
                '%s — %d dimensions, %s, %d row%s',
                $collection->name,
                $collection->dimensions,
                $collection->metric->value,
                $rows,
                $rows === 1 ? '' : 's',
            ));

            if ($indexes === []) {
                $unindexed++;
                $this->warning('  index: NONE — every search is a full scan');

                continue;
            }

            foreach ($indexes as $index) {
                $this->info('  index: ' . $index['definition']);
            }
        }

        if ($unindexed > 0 && $this->option('require-index')) {
            $this->error("{$unindexed} collection(s) without an HNSW index.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
