<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * One OpenList mount point mapped to a local directory and a public URL base.
 */
final class Mount
{
    private string $path;
    private string $root;
    private string $publicBase;

    public function __construct(string $path, string $root, string $publicBase)
    {
        $this->path = rtrim($path, '/');
        $this->root = $root === '/' ? '/' : rtrim($root, '/');
        $this->publicBase = rtrim($publicBase, '/');

        if ($this->root === '') {
            throw new \RuntimeException('mount root cannot be empty');
        }
    }

    /**
     * @param array<string,mixed> $cfg
     */
    public static function fromConfig(string $path, array $cfg): self
    {
        $root = (string)($cfg['root'] ?? '');
        if ($root === '') {
            throw new \RuntimeException('mount root not configured: ' . $path);
        }

        return new self($path, $root, (string)($cfg['public'] ?? ''));
    }

    public function path(): string
    {
        return $this->path;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function publicBase(): string
    {
        return $this->publicBase;
    }

    public function matches(string $requestPath): bool
    {
        if ($this->path === '') {
            return true;
        }

        return $requestPath === $this->path
            || strncmp($requestPath, $this->path . '/', strlen($this->path) + 1) === 0;
    }

    public function relativePath(string $requestPath): string
    {
        return ltrim(substr($requestPath, strlen($this->path)), '/');
    }
}
