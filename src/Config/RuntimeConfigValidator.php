<?php

declare(strict_types=1);

namespace Nexph\Runtime\Config;

/**
 * Runtime configuration validator
 */
final class RuntimeConfigValidator
{
    private array $errors = [];
    private array $warnings = [];

    public function validate(RuntimeConfig $config): bool
    {
        $this->errors = [];
        $this->warnings = [];

        $this->validateWorkerCount($config->workerCount());
        $this->validateQueueDriver($config->queueDriver());
        $this->validateRedisConfig($config->queueDriver(), $config->redisConfig());
        $this->validateApcuAvailability($config->queueDriver(), $config->apcuEnabled());
        $this->validateTimeouts($config);
        $this->validateMemoryLimit($config->memoryLimit());
        $this->validateRestartPolicy($config->restartPolicy());

        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }

    private function validateWorkerCount(int $count): void
    {
        if ($count < 1) {
            $this->errors[] = 'worker_count must be at least 1';
        }

        if ($count > 128) {
            $this->warnings[] = "worker_count is {$count}, which may consume excessive resources";
        }
    }

    private function validateQueueDriver(string $driver): void
    {
        $valid = ['memory', 'apcu', 'redis', 'database'];
        
        if (!in_array($driver, $valid, true)) {
            $this->errors[] = "queue_driver '{$driver}' is invalid. Valid options: " . implode(', ', $valid);
        }
    }

    private function validateRedisConfig(string $driver, array $config): void
    {
        if ($driver !== 'redis') {
            return;
        }

        if (!extension_loaded('redis')) {
            $this->errors[] = 'queue_driver is redis but ext-redis is not loaded';
            return;
        }

        if (empty($config['host'])) {
            $this->errors[] = 'redis.host is required when queue_driver is redis';
        }

        if (!isset($config['port']) || $config['port'] < 1 || $config['port'] > 65535) {
            $this->warnings[] = 'redis.port is not set or invalid, will use default 6379';
        }
    }

    private function validateApcuAvailability(string $driver, bool $enabled): void
    {
        if ($driver !== 'apcu') {
            return;
        }

        if (!extension_loaded('apcu')) {
            $this->errors[] = 'queue_driver is apcu but ext-apcu is not loaded';
            return;
        }

        if (!$enabled) {
            $this->warnings[] = 'queue_driver is apcu but apcu_enabled is false';
        }

        if (!ini_get('apc.enabled')) {
            $this->errors[] = 'ext-apcu is loaded but apc.enabled is off in php.ini';
        }
    }

    private function validateTimeouts(RuntimeConfig $config): void
    {
        if ($config->defaultTimeout() <= 0) {
            $this->errors[] = 'default_timeout must be positive';
        }

        if ($config->defaultDeadline() <= 0) {
            $this->errors[] = 'default_deadline must be positive';
        }

        if ($config->drainTimeout() <= 0) {
            $this->errors[] = 'drain_timeout must be positive';
        }

        if ($config->defaultTimeout() > $config->defaultDeadline()) {
            $this->warnings[] = 'default_timeout exceeds default_deadline';
        }

        if ($config->drainTimeout() > 60) {
            $this->warnings[] = 'drain_timeout is > 60s, may delay shutdown';
        }
    }

    private function validateMemoryLimit(int $limit): void
    {
        if ($limit < 1024 * 1024) {
            $this->errors[] = 'memory_limit must be at least 1MB';
        }

        if ($limit > 2 * 1024 * 1024 * 1024) {
            $this->warnings[] = 'memory_limit is > 2GB, consider splitting workers';
        }
    }

    private function validateRestartPolicy(string $policy): void
    {
        $valid = ['no', 'on_failure', 'always'];
        
        if (!in_array($policy, $valid, true)) {
            $this->errors[] = "restart_policy '{$policy}' is invalid. Valid options: " . implode(', ', $valid);
        }
    }
}
