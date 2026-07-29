<?php

// Minimal PSR-4 autoloader so the pure-PHP core can be tested without Composer
// or a Laravel install in the sandbox.
spl_autoload_register(function (string $class): void {
    $prefix = 'Zuqongtech\\LaravelAnvil\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__.'/../src/'.str_replace('\\', '/', $relative).'.php';

    if (is_file($path)) {
        require $path;
    }
});

// Fixture enums, so enum-rule resolution can be tested for real rather than mocked.
spl_autoload_register(function (string $class): void {
    $prefix = 'Anvil\\Fixtures\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__.'/fixtures/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($path)) {
        require $path;
    }
});
