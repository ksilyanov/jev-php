<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Exceptions;

/** Base for every failure. Never carries credentials. */
class JevException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly int|string|null $providerCode = null,
        public readonly ?string $requestId = null,
        public readonly ?string $body = null,
        public readonly ?string $driver = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
