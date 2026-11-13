<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use OpenListProxy\ByteRange;
use OpenListProxy\Config;
use OpenListProxy\HttpException;
use OpenListProxy\Mime;
use OpenListProxy\Mount;
use OpenListProxy\MountResolver;
use OpenListProxy\Request;
use OpenListProxy\Response;
use OpenListProxy\Signature;

$assertions = 0;

function check(bool $condition, string $label): void
{
    global $assertions;
    $assertions++;

    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $label . "\n");
        exit(1);
    }
}

function same(mixed $expected, mixed $actual, string $label): void
{
    check(
        $expected === $actual,
        $label . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'
    );
}

function throws(callable $fn, int $status, string $label): void
{
    try {
        $fn();
    } catch (HttpException $e) {
        same($status, $e->status(), $label);
        return;
    }

    check(false, $label . ' (no exception)');
}

$base = sys_get_temp_dir() . '/olproxy-tests-' . getmypid();
@mkdir($base . '/root/sub', 0777, true);
$root = realpath($base . '/root');
check($root !== false, 'temp root created');

file_put_contents($root . '/hello.txt', 'hello');
file_put_contents($root . '/sub/中文 文件.txt', 'unicode');
@symlink('/etc/passwd', $root . '/escape.txt');

// --- Signature -------------------------------------------------------------

$signature = new Signature('testtoken');
same(
    '6my7l81kOVxjNQ4avfTWafROuk1VgpatJt-7yLT3S8A=:0',
    $signature->sign('/storagebox/中文 文件.txt', 0),
    'signature matches the reference vector'
);
$signature->verify('/storagebox/中文 文件.txt', '6my7l81kOVxjNQ4avfTWafROuk1VgpatJt-7yLT3S8A=:0');
check(true, 'valid signature accepted');

throws(fn() => $signature->verify('/storagebox/hello.txt', 'bad'), 401, 'bad signature rejected');
$expired = $signature->sign('/storagebox/hello.txt', time() - 10);
throws(fn() => $signature->verify('/storagebox/hello.txt', $expired), 401, 'expired signature rejected');
throws(fn() => (new Signature('CHANGE_ME'))->verify('/x', 'y'), 500, 'placeholder token rejected');

// --- Request path parsing --------------------------------------------------

$request = new Request(
    ['REQUEST_URI' => '/dl.php/storagebox/hello.txt?sign=x', 'SCRIPT_NAME' => '/dl.php', 'REQUEST_METHOD' => 'GET'],
    ['sign' => 'x']
);
same('/storagebox/hello.txt', $request->path('/dl.php'), 'path prefix stripped');
same('x', $request->sign(), 'sign query read');

$request = new Request(
    ['REQUEST_URI' => '/dl.php//storagebox/hello.txt', 'SCRIPT_NAME' => '/dl.php'],
    []
);
same('/storagebox/hello.txt', $request->path('/dl.php'), 'double leading slash collapsed');

$request = new Request(
    ['REQUEST_URI' => '/storagebox/hello.txt', 'SCRIPT_NAME' => '/dl.php'],
    []
);
same('/storagebox/hello.txt', $request->path('/wrong'), 'script-name fallback');

$request = new Request(
    ['REQUEST_URI' => '/dl.php/storagebox/hello.txt', 'SCRIPT_NAME' => '/dl.php'],
    []
);
same('/storagebox/hello.txt', $request->path(''), 'auto-detected prefix');

$request = new Request(
    ['REQUEST_URI' => '/sub/dl.php/storagebox/hello.txt', 'SCRIPT_NAME' => '/sub/dl.php'],
    []
);
same('/storagebox/hello.txt', $request->path(''), 'auto-detected subdirectory prefix');

$request = new Request(['HTTP_ACCEPT_ENCODING' => 'gzip, deflate'], []);
check($request->acceptsGzip(), 'gzip accepted');

$request = new Request(['HTTP_ACCEPT_ENCODING' => 'br, gzip;q=0.8'], []);
check($request->acceptsGzip(), 'gzip with quality accepted');

$request = new Request(['HTTP_ACCEPT_ENCODING' => 'gzip;q=0'], []);
check(!$request->acceptsGzip(), 'gzip;q=0 rejected');

$request = new Request(['HTTP_ACCEPT_ENCODING' => 'gzip;q=0, *;q=1'], []);
check(!$request->acceptsGzip(), 'explicit gzip;q=0 overrides wildcard');

$request = new Request(['HTTP_ACCEPT_ENCODING' => '*'], []);
check($request->acceptsGzip(), 'wildcard gzip accepted');

$request = new Request([], []);
check(!$request->acceptsGzip(), 'missing accept-encoding rejected');

// --- Mount resolution ------------------------------------------------------

$specific = new Mount('/storagebox', $root);
$resolver = new MountResolver([$specific]);

$file = $resolver->resolve('/storagebox/hello.txt');
same($root . '/hello.txt', $file->path(), 'file resolved below root');

$file = $resolver->resolve('/storagebox/sub/中文 文件.txt');
same($root . '/sub/中文 文件.txt', $file->path(), 'unicode path resolved');

throws(fn() => $resolver->resolve('/storagebox/../etc/passwd'), 404, 'path traversal rejected');
throws(fn() => $resolver->resolve('/storagebox/escape.txt'), 404, 'symlink escape rejected');
throws(fn() => $resolver->resolve('/other/hello.txt'), 404, 'unknown mount rejected');

$rootMount = new Mount('/', $root);
$resolver = new MountResolver([$rootMount, $specific]);
$file = $resolver->resolve('/storagebox/hello.txt');
same($root . '/hello.txt', $file->path(), 'longest mount wins');

$resolver = new MountResolver([$rootMount]);
$file = $resolver->resolve('/hello.txt');
same($root . '/hello.txt', $file->path(), 'root mount works');

// --- MIME policy -----------------------------------------------------------

same('text/plain', Mime::normalize('Text/Plain; charset=UTF-8'), 'mime parameters stripped');
check(Mime::isCompressible('text/plain'), 'plain text compressible');
check(Mime::isCompressible('text/plain; charset=utf-8'), 'text with charset compressible');
check(Mime::isCompressible('application/json'), 'json compressible');
check(Mime::isCompressible('application/xml'), 'xml compressible');
check(Mime::isCompressible('image/svg+xml'), 'svg compressible');
check(!Mime::isCompressible('video/mp4'), 'video is not compressible');
check(!Mime::isCompressible('image/png'), 'png is not compressible');
check(!Mime::isCompressible('application/zip'), 'zip is not compressible');
check(!Mime::isCompressible('application/pdf'), 'pdf is not compressible');
check(!Mime::isCompressible('application/octet-stream'), 'octet-stream is not compressible');
check(!Mime::isCompressible(''), 'empty mime is not compressible');
check(Mime::isInline('video/mp4'), 'video inline');
check(Mime::isInline('text/vtt'), 'vtt inline');
check(!Mime::isInline('application/zip'), 'zip attachment');

// --- Byte ranges -----------------------------------------------------------

$range = ByteRange::tryParse('bytes=0-4', 22);
same(0, $range->start(), 'range start');
same(4, $range->end(), 'range end');
same(5, $range->length(), 'range length');
check($range->partial(), 'range is partial');

$range = ByteRange::tryParse('bytes=5-', 22);
same(5, $range->start(), 'open-ended range start');
same(21, $range->end(), 'open-ended range end');

$range = ByteRange::tryParse('bytes=-5', 22);
same(17, $range->start(), 'suffix range start');
same(21, $range->end(), 'suffix range end');

check(ByteRange::tryParse('bytes=100-200', 22) === null, 'unsatisfiable range rejected');

$range = ByteRange::tryParse(null, 22);
same(0, $range->start(), 'full response start');
same(21, $range->end(), 'full response end');
check(!$range->partial(), 'full response is not partial');

// --- Transfer planning -------------------------------------------------------

same('sendfile', Response::planTransfer(true, false, 'GET'), 'binary GET uses sendfile when enabled');
same('sendfile', Response::planTransfer(true, false, 'HEAD'), 'HEAD uses sendfile when enabled');
same('compress', Response::planTransfer(true, true, 'GET'), 'compressible text keeps PHP gzip when enabled');
same('stream', Response::planTransfer(false, false, 'GET'), 'binary GET streams when disabled');
same('head', Response::planTransfer(false, false, 'HEAD'), 'HEAD stays in PHP when disabled');
same('compress', Response::planTransfer(false, true, 'GET'), 'text gzip unchanged when disabled');

// --- Config ----------------------------------------------------------------

$configFile = $base . '/config.php';
file_put_contents(
    $configFile,
    "<?php return ['token' => 't', 'path_prefix' => '/dl.php', 'mounts' => ['/storagebox' => ['root' => "
    . var_export($root, true)
    . "]]];"
);

$config = Config::fromFile($configFile);
same('t', $config->token(), 'config token');
same(1, count($config->mounts()), 'config mount count');
same(false, $config->xsendfile(), 'xsendfile defaults to false');

$xsendfileConfig = $base . '/xsendfile-config.php';
file_put_contents(
    $xsendfileConfig,
    "<?php return ['token' => 't', 'xsendfile' => true, 'mounts' => ['/storagebox' => ['root' => "
    . var_export($root, true)
    . "]]];"
);
same(true, Config::fromFile($xsendfileConfig)->xsendfile(), 'xsendfile opt-in read');

$legacyConfig = $base . '/legacy-config.php';
file_put_contents(
    $legacyConfig,
    "<?php return ['token' => 't', 'require_sign' => false, 'stream_fallback' => false, "
    . "'mounts' => ['/storagebox' => ['root' => " . var_export($root, true) . ", 'public' => '/f']]];"
);
$legacy = Config::fromFile($legacyConfig);
same(1, count($legacy->mounts()), 'legacy config still loads');

$badConfig = $base . '/bad-config.php';
file_put_contents($badConfig, "<?php return ['mounts' => []];");
try {
    Config::fromFile($badConfig);
    check(false, 'empty mounts rejected');
} catch (RuntimeException $e) {
    check(true, 'empty mounts rejected');
}

// --- Cleanup ---------------------------------------------------------------

@unlink($root . '/escape.txt');
@unlink($root . '/sub/中文 文件.txt');
@unlink($root . '/hello.txt');
@rmdir($root . '/sub');
@rmdir($root);
@unlink($configFile);
@unlink($xsendfileConfig);
@unlink($legacyConfig);
@unlink($badConfig);
@rmdir($base);

echo 'OK (' . $assertions . " assertions)\n";
