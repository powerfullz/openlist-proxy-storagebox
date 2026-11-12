<?php

declare(strict_types=1);

/**
 * Public entry point.
 *
 * Upload the deployable files into public_html, create the static symlink,
 * copy config.sample.php to config.php, and fill in the OpenList token plus
 * your mount mapping.
 */

require __DIR__ . '/bootstrap.php';

\OpenListProxy\Application::run($_SERVER, $_GET);
