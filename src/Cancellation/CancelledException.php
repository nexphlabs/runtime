<?php

declare(strict_types=1);

namespace Nexph\Runtime\Cancellation;

use RuntimeException;

/**
 * Exception thrown when operation is cancelled
 */
final class CancelledException extends RuntimeException
{
    public function __construct(string $reason = '')
    {
        parent::__construct($reason ?: 'Operation cancelled', 0, null);
    }
}
