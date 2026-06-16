<?php
/**
 * Shared bootstrap: a tiny PSR-4-ish autoloader for the CompanyFinder
 * namespace plus the loaded config array. Included by the CLI scripts and the
 * web entry points.
 *
 * @return array<string,mixed> the config
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'CompanyFinder\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

return require dirname(__DIR__) . '/config.php';
