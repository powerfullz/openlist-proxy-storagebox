<?php

declare(strict_types=1);

/**
 * Public entry point.
 *
 * Upload the deployable files into public_html, copy config.sample.php to
 * config.php, and fill in the OpenList token plus your mount mapping.
 * Every download is verified against the OpenList signature and streamed
 * by this script; no static file directory is exposed.
 */

require __DIR__ . '/bootstrap.php';

\OpenListProxy\Application::run($_SERVER, $_GET);
