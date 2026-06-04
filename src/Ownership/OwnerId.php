<?php

declare(strict_types=1);

namespace Nexph\Runtime\Ownership;

/**
 * Unique identifier for runtime owners
 */
final class OwnerId
{
    private function __construct(
        private readonly string $id
    ) {
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }

    public static function fromString(string $id): self
    {
        return new self($id);
    }

    public function toString(): string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return $this->id;
    }

    public function equals(OwnerId $other): bool
    {
        return $this->id === $other->id;
    }
}
