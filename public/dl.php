<?php

declare(strict_types=1);

/**
 * Public entry point.
 *
 * Upload the contents of public/ into public_html, create the static symlink,
 * copy openlist-proxy/config.sample.php to openlist-proxy/config.php, and fill
 * in the OpenList token plus your mount mapping.
 */

require __DIR__ . '/bootstrap.php';

\OpenListProxy\Application::run($_SERVER, $_GET);
