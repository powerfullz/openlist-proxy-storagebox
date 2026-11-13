<?php

declare(strict_types=1);

use OpenListProxy\Signature;

require __DIR__ . '/../bootstrap.php';

/**
 * Generate an OpenList-compatible download proxy URL.
 *
 * Usage:
 *   php scripts/sign.php <openlist-path> [base-url] [token] [expire]
 *
 * Examples:
 *   php scripts/sign.php /storagebox/hello.txt
 *   php scripts/sign.php /storagebox/hello.txt https://storage-fra.l3zc.com/dl.php
 *   php scripts/sign.php /storagebox/hello.txt https://storage-fra.l3zc.com/dl.php TOKEN 0
 */

$path = $argv[1] ?? '';
if ($path === '') {
    fwrite(STDERR, "usage: php sign.php <openlist-path> [base-url] [token] [expire]\n");
    exit(2);
}
if ($path[0] !== '/') {
    $path = '/' . $path;
}

$base = $argv[2] ?? null;
$token = $argv[3] ?? null;
$expire = isset($argv[4]) ? (int)$argv[4] : 0;

$config = null;
$configFile = __DIR__ . '/../config.php';
if (is_file($configFile)) {
    $config = require $configFile;
}

if ($token === null) {
    if ($config === null) {
        fwrite(STDERR, "config.php not found; pass token explicitly\n");
        exit(2);
    }
    $token = (string)($config['token'] ?? '');
}

if ($base === null && $config !== null) {
    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
    if ($baseUrl !== '') {
        $prefix = (string)($config['path_prefix'] ?? '');
        $base = $baseUrl . ($prefix !== '' ? $prefix : '/dl.php');
    }
}

$signature = new Signature((string)$token);
$sig = $signature->sign($path, $expire);

// Encode each segment the way OpenList does, so the URL is safe to paste
// into curl even when the file name contains spaces or non-ASCII characters.
$encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

if ($base === null) {
    echo $encodedPath, '?sign=', $sig, "\n";
} else {
    echo rtrim((string)$base, '/'), $encodedPath, '?sign=', $sig, "\n";
}
