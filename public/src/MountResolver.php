<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * Finds the longest matching mount and validates the path below its root.
 */
final class MountResolver
{
    /**
     * @param Mount[] $mounts
     */
    public function __construct(private array $mounts)
    {
    }

    public function resolve(string $requestPath): ResolvedFile
    {
        $mount = $this->findMount($requestPath);
        if ($mount === null) {
            throw new HttpException(404, 'mount not found');
        }

        $relative = $mount->relativePath($requestPath);
        if ($relative === '') {
            throw new HttpException(404, 'not a file');
        }
        if (strpos($relative, "\0") !== false) {
            throw new HttpException(400, 'bad path');
        }

        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new HttpException(404, 'bad path');
            }
        }

        $root = $mount->root();
        $file = ($root === '/' ? '' : $root) . '/' . implode('/', $segments);
        $rootReal = realpath($root);
        $fileReal = realpath($file);

        if ($rootReal === false || $fileReal === false) {
            throw new HttpException(404, 'not found');
        }

        $rootPrefix = $rootReal === '/' ? '/' : $rootReal . DIRECTORY_SEPARATOR;
        if (strncmp($fileReal, $rootPrefix, strlen($rootPrefix)) !== 0
            || !is_file($fileReal)
            || !is_readable($fileReal)
        ) {
            throw new HttpException(404, 'not found');
        }

        return new ResolvedFile($mount, $segments, $fileReal);
    }

    private function findMount(string $requestPath): ?Mount
    {
        $best = null;

        foreach ($this->mounts as $mount) {
            if (!$mount->matches($requestPath)) {
                continue;
            }
            if ($best === null || strlen($mount->path()) > strlen($best->path())) {
                $best = $mount;
            }
        }

        return $best;
    }
}
