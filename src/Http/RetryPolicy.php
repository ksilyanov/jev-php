<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Http;

use Psr\Http\Message\ResponseInterface;

/** Same defaults as TypeSafe's official SDKs. */
final class RetryPolicy
{
    public function __construct(
        public readonly int $maxRetries = 2,
        public readonly float $backoffInitial = 0.5,
        public readonly float $backoffMax = 5.0,
        public readonly float $jitter = 0.25,
    ) {
    }

    /** Seconds to wait before retry number $retry (1-based). A server hint wins over backoff. */
    public function delay(int $retry, ?ResponseInterface $response = null): float
    {
        $hint = $response !== null ? self::retryAfter($response) : null;
        if ($hint !== null) {
            return $hint;
        }

        $base = min($this->backoffMax, $this->backoffInitial * (2 ** ($retry - 1)));

        return max(0.0, $base * (1 + $this->jitter * (mt_rand() / mt_getrandmax() * 2 - 1)));
    }

    public static function retryAfter(ResponseInterface $response): ?float
    {
        $ms = $response->getHeaderLine('retry-after-ms');
        if ($ms !== '' && is_numeric($ms)) {
            return max(0.0, (float) $ms / 1000);
        }

        $value = $response->getHeaderLine('Retry-After');
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $at = strtotime($value);

        return $at === false ? null : max(0.0, (float) ($at - time()));
    }
}
