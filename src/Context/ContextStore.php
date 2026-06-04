<?php

declare(strict_types=1);

namespace Nexph\Runtime\Context;

use Fiber;
use WeakMap;

/**
 * Storage for runtime context per Fiber and global scope
 */
final class ContextStore
{
    private static ?self $instance = null;
    private RuntimeContext $globalContext;
    private WeakMap $fiberContexts;
    private array $keyedContexts = [];

    private function __construct()
    {
        $this->globalContext = RuntimeContext::create();
        $this->fiberContexts = new WeakMap();
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function current(): RuntimeContext
    {
        $fiber = Fiber::getCurrent();
        
        if ($fiber !== null && isset($this->fiberContexts[$fiber])) {
            return $this->fiberContexts[$fiber];
        }

        return $this->globalContext;
    }

    public function set(RuntimeContext $context): void
    {
        $fiber = Fiber::getCurrent();
        
        if ($fiber !== null) {
            $this->fiberContexts[$fiber] = $context;
        } else {
            $this->globalContext = $context;
        }
    }

    public function runWith(RuntimeContext $context, callable $fn): mixed
    {
        $previous = $this->current();
        $this->set($context);
        
        try {
            return $fn();
        } finally {
            $this->set($previous);
        }
    }

    public function setKeyed(string $key, RuntimeContext $context): void
    {
        $this->keyedContexts[$key] = $context;
    }

    public function getKeyed(string $key): ?RuntimeContext
    {
        return $this->keyedContexts[$key] ?? null;
    }

    public function removeKeyed(string $key): void
    {
        unset($this->keyedContexts[$key]);
    }

    public function cleanup(string $key): void
    {
        $this->removeKeyed($key);
    }
}
