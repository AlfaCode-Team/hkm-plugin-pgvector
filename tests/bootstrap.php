<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * THE KERNEL'S `vendor/autoload.php` IS REQUIRED, not just its `src/`: the Unit
 * suite uses `AlfacodeTeam\Ground\Fakes\FakeDatabase` and the real kernel
 * containers, and the Integration suite additionally needs the Database plugin's
 * real adapter (a sibling checkout, mapped below). A hand-written map for this
 * plugin's own namespace cannot provide any of those.
 *
 * Order, most specific first:
 *   1. HKM_KERNEL_PATH          — an explicit checkout
 *   2. vendor/autoload.php      — composer-installed (CI)
 *   3. ../../../PHP             — the conventional workspace layout
 */

$pluginRoot = \dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($pluginRoot): void {
    // Test support first: `Tests\Plugins\Pgvector\` is not matched by the
    // `Plugins\Pgvector\` prefix, but listing it second would still be
    // wrong the day somebody reorders them — so the order is explicit and
    // commented.
    $map = [
        'Tests\\Plugins\\Pgvector\\' => $pluginRoot . '/tests/',
        'Plugins\\Pgvector\\'        => $pluginRoot . '/',
        // The REAL DatabasePort adapter, so the Integration suite exercises the
        // upsert compiler and the binding path production uses rather than a
        // test double that could disagree with it. A sibling checkout, not a
        // dependency: when it is absent the Integration suite skips itself.
        'Plugins\\Database\\'        => \dirname($pluginRoot) . '/hkm-plugin-database/',
    ];

    foreach ($map as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, \strlen($prefix));
        $path     = $base . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
});

$kernelRoot = getenv('HKM_KERNEL_PATH') ?: null;

if ($kernelRoot === null && is_file($pluginRoot . '/vendor/autoload.php')) {
    require_once $pluginRoot . '/vendor/autoload.php';
    $kernelRoot = false; // composer-installed: everything is already on the path
}

if ($kernelRoot !== false) {
    $kernelRoot ??= \dirname($pluginRoot, 3) . '/PHP';

    if (!is_dir($kernelRoot . '/src/Kernel')) {
        fwrite(STDERR, \sprintf(
            "\nCannot find the kernel. Looked in %s.\nSet HKM_KERNEL_PATH to a checkout, or run"
            . " `composer install`.\n\n",
            $kernelRoot,
        ));
        exit(1);
    }

    // The kernel's own composer autoloader, which carries the path packages:
    // alfacode-team/ground (MigratedSqlite, FrozenClock) and
    // alfacode-team/let-migrate (the migration runner the integration suite
    // needs). Falling back to a bare src/ map would leave the integration
    // suite failing on a missing class rather than on the code under test.
    if (is_file($kernelRoot . '/vendor/autoload.php')) {
        require_once $kernelRoot . '/vendor/autoload.php';
    } else {
        fwrite(STDERR, \sprintf(
            "\nFound the kernel at %s but it has no vendor/autoload.php.\n"
            . "Run `composer install` there — the integration suite needs LetMigrate and Ground.\n\n",
            $kernelRoot,
        ));
        exit(1);
    }

    if (is_file($kernelRoot . '/src/Kernel/Support/helpers.php')) {
        require_once $kernelRoot . '/src/Kernel/Support/helpers.php';
    }
}
