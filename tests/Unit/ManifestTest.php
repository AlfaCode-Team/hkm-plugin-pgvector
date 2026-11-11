<?php

declare(strict_types=1);

namespace Tests\Plugins\Pgvector\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\VectorStorePort;
use PHPUnit\Framework\TestCase;
use Plugins\Pgvector\Provider;

/**
 * module.json is the single source of truth and Provider is documentation that
 * must mirror it; a drift between them is a boot failure or a silent mismatch.
 */
final class ManifestTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        return json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/module.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_provider_mirrors_the_manifest(): void
    {
        $m = self::manifest();
        $p = new Provider();

        self::assertSame($m['solves'], $p->solves());
        self::assertSame($m['requires'], $p->requires());
        self::assertSame($m['exposes'], $p->exposes());
        self::assertSame(VectorStorePort::class, $p->exposes()[0]);
    }

    public function test_requires_names_a_domain_never_a_class(): void
    {
        foreach (self::manifest()['requires'] as $entry) {
            self::assertStringNotContainsString('\\', $entry);
            self::assertMatchesRegularExpression('/^[a-z]+\.[a-z]+$/', $entry);
        }
    }

    public function test_every_env_var_the_plugin_reads_is_declared_in_config(): void
    {
        $declared = array_map(
            static fn (string|array $c): string => \is_array($c) ? $c['key'] : $c,
            self::manifest()['config'],
        );

        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/Infrastructure/Config/PgvectorConfig.php');
        preg_match_all("/env\\('([A-Z0-9_]+)'/", $source, $m);

        self::assertNotEmpty($m[1]);
        self::assertEqualsCanonicalizing($declared, array_values(array_unique($m[1])));
    }

    public function test_no_env_var_is_read_with_getenv_and_no_credential_is_configured_here(): void
    {
        $root = \dirname(__DIR__, 2);

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/Infrastructure')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                // Code, not prose: a docblock may say "never getenv()". Tokenise and look for a call.
                $tokens = token_get_all((string) file_get_contents($file->getPathname()));

                foreach ($tokens as $i => $token) {
                    if (\is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'getenv') {
                        self::fail($file->getFilename() . ' calls getenv(); use env()');
                    }
                }

                $this->addToAssertionCount(1);
            }
        }

        foreach (self::manifest()['config'] as $c) {
            $key = \is_array($c) ? $c['key'] : $c;
            self::assertDoesNotMatchRegularExpression('/PASS|SECRET|TOKEN|KEY|DSN/i', $key, 'the connection belongs to the Database plugin');
        }
    }

    public function test_no_route_is_declared_and_the_command_exists(): void
    {
        $m = self::manifest();

        self::assertSame([], $m['routes']);

        foreach ($m['commands'] as $class) {
            self::assertTrue(class_exists($class), $class);
        }
    }

    public function test_kernel_constraint_is_present(): void
    {
        self::assertMatchesRegularExpression('/^\^\d+\.\d+/', self::manifest()['kernel']);
    }
}
