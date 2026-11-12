<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * HTTP responses: short errors, the 302 redirect, and the Range-capable
 * stream fallback.
 */
final class Response
{
    public static function text(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $message, "\n";
        exit;
    }

    public static function redirect(string $url): void
    {
        header('Cache-Control: private, max-age=0, no-store');
        header('Referrer-Policy: no-referrer');
        header('Location: ' . $url, true, 302);
        exit;
    }

    public static function stream(string $file, string $method, ?string $rangeHeader): void
    {
        $size = (int)filesize($file);
        $range = ByteRange::tryParse($rangeHeader, $size);

        if ($range === null) {
            header('Content-Range: bytes */' . $size);
            self::text(416, 'range not satisfiable');
        }

        http_response_code($range->partial() ? 206 : 200);

        $mime = function_exists('mime_content_type') ? (mime_content_type($file) ?: '') : '';
        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $range->length());
        header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode(basename($file)));
        header('Cache-Control: private, max-age=0, no-store');

        if ($range->partial()) {
            header('Content-Range: bytes ' . $range->start() . '-' . $range->end() . '/' . $size);
        }

        if ($method === 'HEAD') {
            exit;
        }

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
