<?php

declare(strict_types=1);

namespace Nexph\Runtime\Cancellation;

/**
 * Source for creating and managing cancellation tokens
 */
final class CancellationSource
{
    private CancellationToken $token;

    public function __construct(?CancellationToken $parent = null)
    {
        $this->token = new CancellationToken($parent);
    }

    public function token(): CancellationToken
    {
        return $this->token;
    }

    public function cancel(string $reason = ''): void
    {
        $this->token->cancel($reason);
    }

    public function isCancelled(): bool
    {
        return $this->token->isCancelled();
    }
}
