<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * MIME type helpers shared by the response headers and the compression policy.
 */
final class Mime
{
    /**
     * Strip parameters and normalise the type, e.g.
     * "Text/Plain; charset=UTF-8" -> "text/plain".
     */
    public static function normalize(string $mime): string
    {
        return strtolower(trim(explode(';', $mime, 2)[0]));
    }

    /**
     * Only text-like content is allowed through gzip. Binary formats are
     * deliberately absent, even if a client advertises gzip support.
     */
    public static function isCompressible(string $mime): bool
    {
        $mime = self::normalize($mime);

        if ($mime === '') {
            return false;
        }

        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        return in_array(
            $mime,
            [
                'application/json',
                'application/ld+json',
                'application/xml',
                'application/xhtml+xml',
                'application/javascript',
                'application/x-javascript',
                'image/svg+xml',
                'application/x-subrip',
                'application/vnd.apple.mpegurl',
                'application/dash+xml',
            ],
            true
        );
    }

    /**
     * Keep audio/video, streaming manifests and WebVTT playable in the
     * browser; everything else is served as a download.
     */
    public static function isInline(string $mime): bool
    {
        $mime = self::normalize($mime);

        if ($mime === '') {
            return false;
        }

        if (str_starts_with($mime, 'video/') || str_starts_with($mime, 'audio/')) {
            return true;
        }

        return in_array(
            $mime,
            [
                'application/vnd.apple.mpegurl',
                'application/dash+xml',
                'application/x-mpegurl',
                'text/vtt',
            ],
            true
        );
    }
}
