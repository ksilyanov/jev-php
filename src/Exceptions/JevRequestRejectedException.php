<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Exceptions;

/** This particular request was rejected (too long, malformed, unencodable). Other requests may succeed. */
class JevRequestRejectedException extends JevException
{
}
