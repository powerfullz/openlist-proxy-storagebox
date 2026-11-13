<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * The small slice of the HTTP request that this proxy actually needs.
 */
final class Request
{
    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $query
     */
    public function __construct(
        private array $server,
        private array $query
    ) {
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $query
     */
    public static function from(array $server, array $query): self
    {
        return new self($server, $query);
    }

    /**
     * Recover the OpenList path after the configured proxy prefix.
     */
    public function path(string $prefix): string
    {
        $requestPath = parse_url($this->requestUri(), PHP_URL_PATH);
        if (!is_string($requestPath)) {
            throw new HttpException(400, 'bad request');
        }
        $requestPath = rawurldecode($requestPath);

        $prefixLen = strlen($prefix);
        $prefixMatch = $prefix !== ''
            && strncmp($requestPath, $prefix, $prefixLen) === 0
            && (strlen($requestPath) === $prefixLen || $requestPath[$prefixLen] === '/');

        if ($prefixMatch) {
            $path = substr($requestPath, $prefixLen);
        } else {
            $script = $this->scriptName();
            $scriptLen = strlen($script);
            $scriptMatch = $script !== ''
                && strncmp($requestPath, $script, $scriptLen) === 0
                && (strlen($requestPath) === $scriptLen || $requestPath[$scriptLen] === '/');
            $path = $scriptMatch ? substr($requestPath, $scriptLen) : $requestPath;
        }

        if ($path === '') {
            throw new HttpException(404, 'missing path');
        }

        // Collapse extra leading slashes so a Download Proxy URL ending in "/"
        // still matches the signed OpenList path.
        return '/' . ltrim($path, '/');
    }

    public function sign(): string
    {
        return isset($this->query['sign']) ? (string)$this->query['sign'] : '';
    }

    public function method(): string
    {
        return strtoupper((string)($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function range(): ?string
    {
        $range = $this->server['HTTP_RANGE'] ?? null;

        return is_string($range) ? $range : null;
    }

    /**
     * Whether the client explicitly accepts gzip. Explicit gzip;q=0 wins
     * over a wildcard entry.
     */
    public function acceptsGzip(): bool
    {
        $header = (string)($this->server['HTTP_ACCEPT_ENCODING'] ?? '');
        if ($header === '') {
            return false;
        }

        $gzip = null;
        $wildcard = null;

        foreach (explode(',', $header) as $part) {
            $tokens = explode(';', trim($part));
            $coding = strtolower(trim($tokens[0]));
            if ($coding !== 'gzip' && $coding !== '*') {
                continue;
            }

            $q = 1.0;
            for ($i = 1; $i < count($tokens); $i++) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $tokens[$i], $m)) {
                    $q = (float)$m[1];
                }
            }

            if ($coding === 'gzip') {
                $gzip = $q;
            } else {
                $wildcard = $q;
            }
        }

        return ($gzip ?? $wildcard ?? 0.0) > 0.0;
    }

    private function requestUri(): string
    {
        return (string)($this->server['REQUEST_URI'] ?? '/');
    }

    private function scriptName(): string
    {
        return (string)($this->server['SCRIPT_NAME'] ?? '');
    }
}
