<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Http;

use Ksilyanov\Jev\Exceptions\JevUnavailableException;
use Psr\Http\Message\ResponseInterface;

/** @internal carries the HTTP response to the retry loop (for Retry-After); never leaves the driver. */
final class RetryableUnavailable extends JevUnavailableException
{
    public function __construct(
        string $message,
        public readonly ?ResponseInterface $response,
        ?int $status = null,
        int|string|null $providerCode = null,
        ?string $requestId = null,
        ?string $body = null,
        ?string $driver = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $providerCode, $requestId, $body !== null ? mb_substr($body, 0, 2000) : null, $driver, $previous);
    }

    public function withoutResponse(): JevUnavailableException
    {
        return new JevUnavailableException($this->getMessage(), $this->status, $this->providerCode, $this->requestId, $this->body, $this->driver, $this->getPrevious());
    }
}
