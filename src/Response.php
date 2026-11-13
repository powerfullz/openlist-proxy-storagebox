<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * HTTP responses: short errors and the Range-capable file stream.
 */
final class Response
{
    public static function text(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, no-transform');
        header('Vary: Accept-Encoding');
        echo $message, "\n";
        exit;
    }

    public static function stream(
        string $file,
        string $method,
        ?string $rangeHeader,
        bool $acceptsGzip = false
    ): void {
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                break;
            }
        }

        // Large downloads can easily outlive the default max_execution_time.
        @set_time_limit(0);
        header_remove('X-Powered-By');

        $size = filesize($file);
        if ($size === false) {
            self::text(500, 'cannot read file');
        }

        $size = (int)$size;
        $range = ByteRange::tryParse($rangeHeader, $size);

        if ($range === null) {
            header('Content-Range: bytes */' . $size);
            self::text(416, 'range not satisfiable');
        }

        http_response_code($range->partial() ? 206 : 200);

        $mimeRaw = function_exists('mime_content_type') ? (mime_content_type($file) ?: '') : '';
        $mime = Mime::normalize($mimeRaw);
        $compressible = Mime::isCompressible($mime);
        $compress = $compressible
            && !$range->partial()
            && $method !== 'HEAD'
            && $acceptsGzip
            && $size >= 1024
            && function_exists('gzencode')
            && function_exists('deflate_init');

        header('Content-Type: ' . ($mimeRaw !== '' ? $mimeRaw : 'application/octet-stream'));
        header('Accept-Ranges: bytes');

        if ($compressible) {
            header('Vary: Accept-Encoding');
        }

        // Range responses must stay byte-exact. Binary responses are never
        // compressed. .htaccess/Nginx enforce the same policy; this is a
        // best-effort guard for Apache mod_php setups.
        if ($range->partial() || !$compressible) {
            if (function_exists('apache_setenv')) {
                @apache_setenv('no-gzip', '1');
            }
        }

        header(
            'Content-Disposition: '
            . (Mime::isInline($mime) ? 'inline' : 'attachment')
            . '; filename*=UTF-8\'\''
            . rawurlencode(basename($file))
        );
        header('Cache-Control: private, max-age=0, no-store, no-transform');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');

        if ($range->partial()) {
            header('Content-Range: bytes ' . $range->start() . '-' . $range->end() . '/' . $size);
        }

        if ($method === 'HEAD') {
            header('Content-Length: ' . $range->length());
            exit;
        }

        // Small text files: compress in PHP so the compressed length is known.
        if ($compress && $size <= 2 * 1024 * 1024) {
            $data = @file_get_contents($file);
            $gz = $data === false ? false : gzencode($data, 6);

            if ($gz !== false) {
                header('Content-Encoding: gzip');
                header('Content-Length: ' . strlen($gz));
                echo $gz;
                exit;
            }

            $compress = false;
        }

        // Larger text files: stream gzip. Content-Length is unknown.
        if ($compress) {
            $ctx = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

            if ($ctx === false) {
                $compress = false;
            } else {
                header('Content-Encoding: gzip');

                $fp = fopen($file, 'rb');
                if ($fp === false) {
                    exit;
                }

                $remaining = $size;
                while ($remaining > 0 && !feof($fp)) {
                    $chunk = fread($fp, (int)min(1024 * 64, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $out = deflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
                    if ($out !== false) {
                        echo $out;
                    }
                    $remaining -= strlen($chunk);
                    if (connection_aborted()) {
                        break;
                    }
                }

                $out = deflate_add($ctx, '', ZLIB_FINISH);
                if ($out !== false) {
                    echo $out;
                }
                fclose($fp);
                exit;
            }
        }

        header('Content-Length: ' . $range->length());

        $fp = fopen($file, 'rb');
        if ($fp === false) {
            exit;
        }
        fseek($fp, $range->start());

        $remaining = $range->length();
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, (int)min(1024 * 64, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fp);
        exit;
    }
}
