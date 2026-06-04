<?php

declare(strict_types=1);

namespace Nexph\Runtime\Ownership;

/**
 * Runtime owner representing lifecycle and hierarchy of work units
 */
final class RuntimeOwner
{
    private function __construct(
        private readonly OwnerId $id,
        private readonly OwnerType $type,
        private readonly ?OwnerId $parentId,
        private readonly string $name,
        private readonly float $createdAt,
        private ?float $closedAt,
        private string $status,
        private array $metadata,
    ) {
    }

    public static function create(
        OwnerType $type,
        ?OwnerId $parentId = null,
        string $name = '',
        array $metadata = []
    ): self {
        return new self(
            id: OwnerId::generate(),
            type: $type,
            parentId: $parentId,
            name: $name ?: $type->value,
            createdAt: microtime(true),
            closedAt: null,
            status: 'alive',
            metadata: $metadata,
        );
    }

    public function close(string $reason = ''): void
    {
        $this->closedAt = microtime(true);
        $this->status = 'closed';
        if ($reason) {
            $this->metadata['close_reason'] = $reason;
        }
        
        // Release all resources owned by this owner
        if (class_exists('\Nexph\Runtime\Resource\ResourceRegistry')) {
            \Nexph\Runtime\Resource\ResourceRegistry::instance()->releaseByOwner($this->id, $reason);
        }
    }

    public function id(): OwnerId
    {
        return $this->id;
    }

    public function type(): OwnerType
    {
        return $this->type;
    }

    public function parentId(): ?OwnerId
    {
        return $this->parentId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function createdAt(): float
    {
        return $this->createdAt;
    }

    public function closedAt(): ?float
    {
        return $this->closedAt;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function isAlive(): bool
    {
        return $this->status === 'alive';
    }

    public function duration(): ?float
    {
        return $this->closedAt !== null 
            ? $this->closedAt - $this->createdAt 
            : null;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'type' => $this->type->value,
            'parent_id' => $this->parentId?->toString(),
            'name' => $this->name,
            'created_at' => $this->createdAt,
            'closed_at' => $this->closedAt,
            'status' => $this->status,
            'metadata' => $this->metadata,
        ];
    }
}
