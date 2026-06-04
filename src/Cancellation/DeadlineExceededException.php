<?php
declare(strict_types=1);

namespace Nexph\Runtime\Cancellation;

final class DeadlineExceededException extends CancelledException
{
    public function __construct(string $message = 'Deadline exceeded')
    {
        parent::__construct($message);
    }
}
