<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Http;

use Ksilyanov\Jev\Exceptions\JevAccessException;
use Ksilyanov\Jev\Exceptions\JevRequestRejectedException;
use Ksilyanov\Jev\Exceptions\JevUnavailableException;

/** HTTP status → failure kind. Drivers check provider-specific codes first. */
final class Failure
{
    /** @return class-string<\Ksilyanov\Jev\Exceptions\JevException> */
    public static function forStatus(int $status): string
    {
        return match (true) {
            $status === 408, $status === 429, $status >= 500 => JevUnavailableException::class,
            in_array($status, [401, 402, 403, 404], true) => JevAccessException::class,
            default => JevRequestRejectedException::class,
        };
    }
}
