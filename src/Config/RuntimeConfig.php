<?php

declare(strict_types=1);

namespace Nexph\Runtime\Config;

/**
 * Runtime configuration
 */
final class RuntimeConfig
{
    private function __construct(
        private readonly int $workerCount,
        private readonly string $queueDriver,
        private readonly array $redisConfig,
        private readonly bool $apcuEnabled,
        private readonly float $defaultTimeout,
        private readonly float $defaultDeadline,
        private readonly float $drainTimeout,
        private readonly int $memoryLimit,
        private readonly string $restartPolicy,
        private readonly array $extra,
    ) {
    }

    public static function fromArray(array $config): self
    {
        return new self(
            workerCount: $config['worker_count'] ?? 1,
            queueDriver: $config['queue_driver'] ?? 'memory',
            redisConfig: $config['redis'] ?? [],
            apcuEnabled: $config['apcu_enabled'] ?? extension_loaded('apcu'),
            defaultTimeout: $config['default_timeout'] ?? 30.0,
            defaultDeadline: $config['default_deadline'] ?? 60.0,
            drainTimeout: $config['drain_timeout'] ?? 10.0,
            memoryLimit: $config['memory_limit'] ?? 128 * 1024 * 1024,
            restartPolicy: $config['restart_policy'] ?? 'on_failure',
            extra: $config['extra'] ?? [],
        );
    }

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    public function workerCount(): int
    {
        return $this->workerCount;
    }

    public function queueDriver(): string
    {
        return $this->queueDriver;
    }

    public function redisConfig(): array
    {
        return $this->redisConfig;
    }

    public function apcuEnabled(): bool
    {
        return $this->apcuEnabled;
    }

    public function defaultTimeout(): float
    {
        return $this->defaultTimeout;
    }

    public function defaultDeadline(): float
    {
        return $this->defaultDeadline;
    }

    public function drainTimeout(): float
    {
        return $this->drainTimeout;
    }

    public function memoryLimit(): int
    {
        return $this->memoryLimit;
    }

    public function restartPolicy(): string
    {
        return $this->restartPolicy;
    }

    public function extra(): array
    {
        return $this->extra;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public function toArray(): array
    {
        return [
            'worker_count' => $this->workerCount,
            'queue_driver' => $this->queueDriver,
            'redis' => $this->redisConfig,
            'apcu_enabled' => $this->apcuEnabled,
            'default_timeout' => $this->defaultTimeout,
            'default_deadline' => $this->defaultDeadline,
            'drain_timeout' => $this->drainTimeout,
            'memory_limit' => $this->memoryLimit,
            'restart_policy' => $this->restartPolicy,
            'extra' => $this->extra,
        ];
    }
}
