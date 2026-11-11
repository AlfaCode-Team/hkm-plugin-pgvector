<?php

declare(strict_types=1);

namespace Plugins\Pgvector;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Vector\VectorStoreException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\VectorStorePort;
use Plugins\Pgvector\Infrastructure\Cli\VectorStatusCommand;
use Plugins\Pgvector\Infrastructure\Config\PgvectorConfig;
use Plugins\Pgvector\Infrastructure\Persistence\PgvectorStore;

/**
 * pgvector — binds VectorStorePort over the application's DatabasePort.
 *
 * ACTIVATION: an on-demand module. A consumer declares it so the module joins
 * that request's graph:
 *
 *   { "requires": ["vector.store"] }
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHICH OF THE KERNEL'S THREE TRAPS APPLY HERE
 * ────────────────────────────────────────────────────────────────────────────
 * None, and each for a stated reason, so the next change does not walk into one:
 *
 *  • `jobs[]` / `$worker->job()` — this plugin registers no job. The store is a
 *    synchronous primitive. A batch re-embedding job belongs to whoever owns the
 *    source text, and when one is written it must be registered with
 *    `$worker->job('name', Class::class)` in boot(): `jobs[]` in module.json does
 *    NOT do it, and an unregistered logical name resolves to nothing, which
 *    WorkerLoop treats as "skipped" — and skipped ACKS the message.
 *  • `bind()` vs `bindInternal()` for a job handler — n/a, no handler.
 *  • An EventBus listener bound with `bind()` — n/a, no listener (`listens` is
 *    empty). Were one added, EventBus resolves it from the KERNEL scope, so it
 *    would have to be bound publicly.
 *
 * The one binding here, VectorStorePort, is PUBLIC (`bind`) because it is the
 * contract other modules resolve; nothing about it is internal.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * THE DATABASE PORT IS RESOLVED PER SCOPE, NOT CAPTURED AT BOOT
 * ────────────────────────────────────────────────────────────────────────────
 * The Database plugin's DatabasePort is transient — each make() is a new
 * connection — and a tenancy module rebinds it per request. The closure below
 * resolves it when the store is first asked for in a scope, so a store never
 * outlives the connection it was built on and never serves one tenant's request
 * from another tenant's database.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'vector.store';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return ['database.management'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [VectorStorePort::class];
    }

    public function register(ModuleContainer $container): void
    {
        if ($container->has(VectorStorePort::class)) {
            return; // a project already provided its own VectorStorePort
        }

        $container->bind(
            VectorStorePort::class,
            static fn (ModuleContainer $c): PgvectorStore => new PgvectorStore(
                $c->make(DatabasePort::class),
                PgvectorConfig::fromEnv(),
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // The command is DEFERRED and registered as an INSTANCE with a lazy store: it is built
        // from the CoreContainer, where VectorStorePort (bound per request) does not exist, so
        // the command builds its own store from the core's DatabasePort when it actually runs.
        $cli->defer(static function (CliPipeline $cli): void {
            $core = $cli->container();

            $cli->command(new VectorStatusCommand(static function () use ($core): PgvectorStore {
                if (!$core->has(DatabasePort::class)) {
                    throw new VectorStoreException(
                        'No DatabasePort is bound in this process, so there is no PostgreSQL to inspect.',
                        'status',
                    );
                }

                return new PgvectorStore($core->make(DatabasePort::class), PgvectorConfig::fromEnv());
            }));
        });
    }
}
