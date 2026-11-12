<?php

declare(strict_types=1);

namespace OpenListProxy;

/**
 * An expected request failure that maps directly to an HTTP status.
 */
final class HttpException extends \RuntimeException
{
    public function __construct(
        private int $status,
        string $message
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
