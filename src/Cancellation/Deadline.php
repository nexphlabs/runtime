<?php

declare(strict_types=1);

namespace Nexph\Runtime\Cancellation;

/**
 * Deadline for time-bounded operations
 */
final class Deadline
{
    private function __construct(
        private readonly float $deadlineAt
    ) {
    }

    public static function fromSeconds(float $seconds): self
    {
        return new self(microtime(true) + $seconds);
    }

    public static function fromMilliseconds(int $ms): self
    {
        return new self(microtime(true) + ($ms / 1000.0));
    }

    public static function at(float $timestamp): self
    {
        return new self($timestamp);
    }

    public static function none(): self
    {
        return new self(PHP_FLOAT_MAX);
    }

    public function expired(): bool
    {
        return microtime(true) >= $this->deadlineAt;
    }

    public function remainingMs(): int
    {
        $remaining = ($this->deadlineAt - microtime(true)) * 1000;
        return max(0, (int)$remaining);
    }

    public function remainingSeconds(): float
    {
        $remaining = $this->deadlineAt - microtime(true);
        return max(0.0, $remaining);
    }

    public function timestamp(): float
    {
        return $this->deadlineAt;
    }

    public function throwIfExpired(): void
    {
        if ($this->expired()) {
            throw new DeadlineExceededException();
        }
    }
}
