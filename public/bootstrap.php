<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 style autoloader for the OpenListProxy namespace.
 *
 * The app lives outside public_html, so there is no Composer dependency to
 * install on the shared host: one small autoloader is enough.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'OpenListProxy\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
