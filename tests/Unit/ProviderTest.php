<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Unit;

use AlfacodeTeam\Ground\Fakes\FakeDatabase;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\VectorStorePort;
use PHPUnit\Framework\TestCase;
use Plugins\Pgvector\Infrastructure\Persistence\PgvectorStore;
use Plugins\Pgvector\Provider;

/** The binding, in a REAL kernel container rather than described in a comment. */
final class ProviderTest extends TestCase
{
    public function test_the_store_is_a_public_binding_resolved_over_the_database_port(): void
    {
        $core = new CoreContainer();
        $db   = new FakeDatabase();
        $core->instance(DatabasePort::class, $db);

        $module = new ModuleContainer($core);
        $module->setScope('vector.store');
        (new Provider())->register($module);

        // From ANOTHER scope: a bindInternal() would throw ScopeViolationException here.
        $store = $module->makeInScope(VectorStorePort::class, 'some.other.module');

        self::assertInstanceOf(PgvectorStore::class, $store);
        self::assertSame('pgvector (PostgreSQL, over DatabasePort)', $store->describe());
        self::assertSame([], $db->queries, 'resolving the port connects to nothing: the database is touched on first USE');
    }

    public function test_a_project_supplied_port_is_not_overridden(): void
    {
        $module = new ModuleContainer(new CoreContainer());
        $mine   = new PgvectorStore(new FakeDatabase(), \Plugins\Pgvector\Infrastructure\Config\PgvectorConfig::of());
        $module->instance(VectorStorePort::class, $mine);

        (new Provider())->register($module);

        self::assertSame($mine, $module->make(VectorStorePort::class));
    }
}
