<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Exceptions;

/** Transient (connection, timeout, 408, 429, 5xx, out of capacity); retries exhausted. Try again later. */
class JevUnavailableException extends JevException
{
}
