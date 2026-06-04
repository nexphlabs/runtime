<?php

declare(strict_types=1);

namespace Nexph\Runtime\Ownership;

/**
 * Registry for tracking runtime owners and their lifecycle
 */
final class OwnerRegistry
{
    private static ?self $instance = null;
    private array $owners = [];
    private array $childrenIndex = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(RuntimeOwner $owner): void
    {
        $id = $owner->id()->toString();
        $this->owners[$id] = $owner;

        if ($owner->parentId() !== null) {
            $parentId = $owner->parentId()->toString();
            if (!isset($this->childrenIndex[$parentId])) {
                $this->childrenIndex[$parentId] = [];
            }
            $this->childrenIndex[$parentId][$id] = true;
        }
    }

    public function open(
        OwnerType $type,
        ?OwnerId $parent = null,
        array $metadata = []
    ): RuntimeOwner {
        $owner = RuntimeOwner::create($type, $parent, '', $metadata);
        $this->register($owner);
        return $owner;
    }

    public function close(OwnerId|string $id, string $reason = ''): void
    {
        $idStr = $id instanceof OwnerId ? $id->toString() : $id;
        
        if (isset($this->owners[$idStr])) {
            $this->owners[$idStr]->close($reason);
            
            // Close all children recursively
            foreach ($this->childrenOf($idStr) as $child) {
                $this->close($child->id(), 'parent_closed');
            }
        }
    }

    public function get(OwnerId|string $id): ?RuntimeOwner
    {
        $idStr = $id instanceof OwnerId ? $id->toString() : $id;
        return $this->owners[$idStr] ?? null;
    }

    public function childrenOf(OwnerId|string $id): array
    {
        $idStr = $id instanceof OwnerId ? $id->toString() : $id;
        
        if (!isset($this->childrenIndex[$idStr])) {
            return [];
        }

        $children = [];
        foreach (array_keys($this->childrenIndex[$idStr]) as $childId) {
            if (isset($this->owners[$childId])) {
                $children[] = $this->owners[$childId];
            }
        }
        return $children;
    }

    public function parentOf(OwnerId|string $id): ?RuntimeOwner
    {
        $idStr = $id instanceof OwnerId ? $id->toString() : $id;
        $owner = $this->get($idStr);
        
        if ($owner === null || $owner->parentId() === null) {
            return null;
        }

        return $this->get($owner->parentId());
    }

    public function alive(): array
    {
        return array_filter($this->owners, fn($o) => $o->isAlive());
    }

    public function stats(): array
    {
        $byType = [];
        $alive = 0;
        $closed = 0;

        foreach ($this->owners as $owner) {
            $type = $owner->type()->value;
            if (!isset($byType[$type])) {
                $byType[$type] = ['total' => 0, 'alive' => 0, 'closed' => 0];
            }
            $byType[$type]['total']++;
            
            if ($owner->isAlive()) {
                $byType[$type]['alive']++;
                $alive++;
            } else {
                $byType[$type]['closed']++;
                $closed++;
            }
        }

        return [
            'total' => count($this->owners),
            'alive' => $alive,
            'closed' => $closed,
            'by_type' => $byType,
        ];
    }
}
