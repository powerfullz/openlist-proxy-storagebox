<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * Validated, immutable proxy configuration.
 */
final class Config
{
    /**
     * @param Mount[] $mounts
     */
    private function __construct(
        private string $token,
        private string $pathPrefix,
        private bool $requireSign,
        private bool $streamFallback,
        private bool $debug,
        private array $mounts
    ) {
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException('config.php not found: ' . $file);
        }

        $raw = require $file;
        if (!is_array($raw)) {
            throw new \RuntimeException('config.php must return an array');
        }

        $mounts = [];
        foreach (($raw['mounts'] ?? []) as $path => $cfg) {
            if (!is_array($cfg)) {
                throw new \RuntimeException('mount config must be an array: ' . $path);
            }
            $mounts[] = Mount::fromConfig((string)$path, $cfg);
        }

        if ($mounts === []) {
            throw new \RuntimeException('no mounts configured');
        }

        return new self(
            (string)($raw['token'] ?? ''),
            (string)($raw['path_prefix'] ?? ''),
            (bool)($raw['require_sign'] ?? true),
            (bool)($raw['stream_fallback'] ?? false),
            (bool)($raw['debug'] ?? false),
            $mounts
        );
    }

    public function token(): string
    {
        return $this->token;
    }

    public function pathPrefix(): string
    {
        return $this->pathPrefix;
    }

    public function requireSign(): bool
    {
        return $this->requireSign;
    }

    public function streamFallback(): bool
    {
        return $this->streamFallback;
    }

    public function debug(): bool
    {
        return $this->debug;
    }

    /**
     * @return Mount[]
     */
    public function mounts(): array
    {
        return $this->mounts;
    }
}
