<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Exceptions;

/** Credentials, balance, account limit or unknown model. Every call will fail until it is fixed. */
class JevAccessException extends JevException
{
}
