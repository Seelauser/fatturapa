<?php

declare(strict_types=1);

namespace Fatturapa\Exception;

use RuntimeException;

/** Thrown on an unrecoverable SdI transmission failure. */
class TransportException extends RuntimeException
{
}
