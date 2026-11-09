<?php

declare(strict_types=1);

namespace Ksilyanov\Jev\Exceptions;

/** The provider answered, but not with a readable Jev response. Scope unknown: may be one-off or a format change. */
class JevInvalidResponseException extends JevException
{
}
