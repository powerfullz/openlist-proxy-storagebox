<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * A parsed single-range request. Multi-range requests are treated as a
 * normal 200 response, which is what most static servers do in practice.
 */
final class ByteRange
{
    private function __construct(
        private int $start,
        private int $end,
        private int $size,
        private bool $partial
    ) {
    }

    public static function tryParse(?string $header, int $size): ?self
    {
        $start = 0;
        $end = $size > 0 ? $size - 1 : -1;
        $partial = false;

        if ($header !== null && preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            if ($m[1] === '' && $m[2] === '') {
                return new self(0, $end, $size, false);
            }

            if ($m[1] === '') {
                $suffix = (int)$m[2];
                $start = $suffix > 0 ? max(0, $size - $suffix) : 0;
            } else {
                $start = (int)$m[1];
                if ($m[2] !== '') {
                    $end = min((int)$m[2], $size - 1);
                }
            }

            $partial = true;
            if ($start > $end || $start >= $size) {
                return null;
            }
        }

        return new self($start, $end, $size, $partial);
    }

    public function start(): int
    {
        return $this->start;
    }

    public function end(): int
    {
        return $this->end;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function partial(): bool
    {
        return $this->partial;
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }
}
