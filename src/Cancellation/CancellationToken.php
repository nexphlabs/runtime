<?php

declare(strict_types=1);

namespace Nexph\Runtime\Cancellation;

/**
 * Token for cooperative cancellation
 */
final class CancellationToken
{
    private bool $cancelled = false;
    private string $reason = '';
    private array $callbacks = [];

    public function __construct(
        private readonly ?self $parent = null
    ) {
    }

    public function cancel(string $reason = ''): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->reason = $reason;

        foreach ($this->callbacks as $callback) {
            try {
                $callback($reason);
            } catch (\Throwable $e) {
                error_log("Cancellation callback error: " . $e->getMessage());
            }
        }

        $this->callbacks = [];
    }

    public function isCancelled(): bool
    {
        if ($this->cancelled) {
            return true;
        }

        if ($this->parent !== null && $this->parent->isCancelled()) {
            $this->cancel('parent_cancelled');
            return true;
        }

        return false;
    }

    public function reason(): string
    {
        if ($this->cancelled) {
            return $this->reason;
        }

        if ($this->parent !== null && $this->parent->isCancelled()) {
            return $this->parent->reason();
        }

        return '';
    }

    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new CancelledException($this->reason());
        }
    }

    public function onCancel(callable $callback): void
    {
        if ($this->cancelled) {
            $callback($this->reason);
            return;
        }

        $this->callbacks[] = $callback;
    }

    public static function none(): self
    {
        static $none = null;
        if ($none === null) {
            $none = new self();
        }
        return $none;
    }
}
