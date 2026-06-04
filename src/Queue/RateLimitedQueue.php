<?php
declare(strict_types=1);

namespace Nexph\Runtime\Queue;

use Nexph\Runtime\RateLimit\RateLimiter;

final class RateLimitedQueue
{
    private Queue $queue;
    private array $jobLimiters = [];

    public function __construct(Queue $queue)
    {
        $this->queue = $queue;
    }

    public function forJob(string $jobName, RateLimiter $limiter): self
    {
        $this->jobLimiters[$jobName] = $limiter;
        return $this;
    }

    public function push(string $jobName, array $payload = [], array $options = []): string
    {
        $limiter = $this->jobLimiters[$jobName] ?? null;
        if ($limiter && !$limiter->allow($jobName)) {
            throw new \RuntimeException("Rate limit exceeded: {$jobName}");
        }
        return $this->queue->push($jobName, $payload, $options);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->queue->$method(...$args);
    }
}
