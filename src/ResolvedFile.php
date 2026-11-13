<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * A request path that has been validated and mapped to a real local file.
 */
final class ResolvedFile
{
    public function __construct(
        private Mount $mount,
        private string $path
    ) {
    }

    public function mount(): Mount
    {
        return $this->mount;
    }

    public function path(): string
    {
        return $this->path;
    }
}
