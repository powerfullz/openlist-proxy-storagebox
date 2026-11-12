<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * A request path that has been validated and mapped to a real local file.
 */
final class ResolvedFile
{
    /**
     * @param string[] $segments
     */
    public function __construct(
        private Mount $mount,
        private array $segments,
        private string $path
    ) {
    }

    public function mount(): Mount
    {
        return $this->mount;
    }

    /**
     * @return string[]
     */
    public function segments(): array
    {
        return $this->segments;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function publicUrl(): string
    {
        return $this->mount->publicBase()
            . '/'
            . implode('/', array_map('rawurlencode', $this->segments));
    }
}
