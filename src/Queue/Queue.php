<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime\Queue;

use Nexph\Runtime\Runtime;
use Nexph\Runtime\Channel;

/**
 * Adaptive queue abstraction.
 * 
 * Automatically uses coroutine workers when runtime available,
 * falls back to synchronous execution on shared hosting.
 */
class Queue {
    private QueueDriver $driver;
    private array $handlers = [];
    private array $workers = [];
    private bool $running = false;
    private ?Channel $jobChannel = null;
    private QueueMetrics $metrics;
    private int $workerCount = 1;
    private array $config = [];
    
    public function __construct(QueueDriver $driver, array $config = []) {
        $this->driver = $driver;
        $this->metrics = new QueueMetrics();
        $this->config = array_merge([
            'workers' => 1,
            'max_attempts' => 3,
            'retry_delay' => 60,
            'timeout' => 300,
            'memory_limit' => 128 * 1024 * 1024,
            'poll_interval' => 1.0,
            'metrics_interval' => 10,
            'verbose' => false,
            'quiet' => false,
        ], $config);
        $this->workerCount = $this->config['workers'];
    }
    
    /**
     * Register job handler.
     */
    public function register(string $jobName, callable|string $handler): void {
        $this->handlers[$jobName] = $handler;
    }
    
    /**
     * Push job to queue immediately.
     */
    public function push(string $jobName, array $payload = [], array $options = []): string {
        // Capture current context
        $context = Runtime::available() ? Runtime::context() : null;
        
        $job = new Job([
            'id' => $this->generateId(),
            'name' => $jobName,
            'payload' => $payload,
            'status' => JobStatus::PENDING,
            'attempts' => 0,
            'max_attempts' => $options['max_attempts'] ?? $this->config['max_attempts'],
            'timeout' => $options['timeout'] ?? $this->config['timeout'],
            'created_at' => time(),
            'available_at' => time(),
            'metadata' => array_merge(
                $options['metadata'] ?? [],
                $context ? ['_context' => $context->toArray()] : []
            ),
        ]);
        
        $this->driver->push($job);
        $this->metrics->incrementEnqueued();
        
        return $job->id;
    }
    
    /**
     * Push job with delay.
     */
    public function later(int $delay, string $jobName, array $payload = [], array $options = []): string {
        // Capture current context
        $context = Runtime::available() ? Runtime::context() : null;
        
        $job = new Job([
            'id' => $this->generateId(),
            'name' => $jobName,
            'payload' => $payload,
            'status' => JobStatus::PENDING,
            'attempts' => 0,
            'max_attempts' => $options['max_attempts'] ?? $this->config['max_attempts'],
            'timeout' => $options['timeout'] ?? $this->config['timeout'],
            'created_at' => time(),
            'available_at' => time() + $delay,
            'metadata' => array_merge(
                $options['metadata'] ?? [],
                $context ? ['_context' => $context->toArray()] : []
            ),
        ]);
        
        $this->driver->push($job);
        $this->metrics->incrementEnqueued();
        
        return $job->id;
    }
    
    /**
     * Start queue workers (blocking, long-running).
     * Uses coroutines if runtime available, otherwise synchronous.
     * This is a blocking call that runs until stopped.
     */
    public function work(): void {
        if ($this->running) {
            throw new \RuntimeException('Queue workers already running');
        }
        
        $this->running = true;
        
        if (Runtime::available()) {
            $this->workAsync();
        } else {
            $this->workSync();
        }
    }
    
    /**
     * Stop queue workers gracefully.
     */
    public function stop(): void {
        $this->running = false;
        
        // Integrate with DrainController
        if (class_exists('\Nexph\Runtime\Drain\DrainController')) {
            $drainController = \Nexph\Runtime\Drain\DrainController::instance();
            $drainController->stopAccepting();
            
            // Wait for in-flight jobs with timeout
            $timeout = $this->config['drain_timeout'] ?? 30;
            if (!$drainController->waitInFlight($timeout)) {
                $drainController->forceStop('drain_timeout_exceeded');
            }
        }
        
        if ($this->jobChannel !== null) {
            $this->jobChannel->close();
            $this->jobChannel = null;
        }
        
        foreach ($this->workers as $worker) {
            if ($worker['coroutine'] ?? null) {
                $worker['coroutine']->await();
            }
        }
        
        $this->workers = [];
        gc_collect_cycles();
    }
    
    /**
     * Get queue metrics.
     */
    public function metrics(): QueueMetrics {
        return $this->metrics;
    }

    public function driver(): QueueDriver {
        return $this->driver;
    }
    
    /**
     * Get queue status.
     */
    public function status(): array {
        return [
            'running' => $this->running,
            'workers' => count($this->workers),
            'depth' => $this->driver->depth(),
            'metrics' => $this->metrics->toArray(),
        ];
    }

    public function workOnce(int $workerId = 1): bool {
        $job = $this->driver->pop();

        if ($job === null) {
            return false;
        }

        $this->processJob($job, $workerId);
        return true;
    }
    
    /**
     * Async worker mode (coroutine-based).
     */
    private function workAsync(): void {
        if (!$this->config['quiet']) {
            echo "[Queue] Starting {$this->workerCount} async workers\n";
        }
        
        $this->jobChannel = new Channel(100);
        
        for ($i = 0; $i < $this->workerCount; $i++) {
            $workerId = $i + 1;
            $coroutine = Runtime::spawn(function() use ($workerId) {
                $this->workerLoop($workerId);
            });
            
            $this->workers[] = [
                'id' => $workerId,
                'coroutine' => $coroutine,
                'started_at' => time(),
            ];
        }
        
        Runtime::spawn(function() {
            $this->fetchLoop();
        });
        
        if ($this->config['metrics_interval'] > 0 && !$this->config['quiet']) {
            Runtime::spawn(function() {
                $this->metricsLoop();
            });
        }
        
        if (!Runtime::isRunning()) {
            Runtime::run();
        }
    }
    
    /**
     * Sync worker mode (blocking).
     */
    private function workSync(): void {
        if (!$this->config['quiet']) {
            echo "[Queue] Starting synchronous worker\n";
        }
        
        while ($this->running) {
            if (!$this->workOnce(1)) {
                usleep((int)($this->config['poll_interval'] * 1_000_000));
            }
        }
    }
    
    /**
     * Job fetcher loop (async mode).
     */
    private function fetchLoop(): void {
        while ($this->running) {
            $job = $this->driver->pop();
            
            if ($job !== null) {
                $this->jobChannel->send($job);
            } else {
                // No jobs available, sleep and continue
                Runtime::sleep($this->config['poll_interval']);
            }
        }
        
        // Close channel to signal workers
        if ($this->jobChannel !== null) {
            $this->jobChannel->close();
        }
    }
    
    /**
     * Worker loop (async mode).
     */
    private function workerLoop(int $workerId): void {
        while ($this->running) {
            $job = $this->jobChannel->receive();
            
            if ($job === null) {
                break;
            }
            
            $this->processJob($job, $workerId);
        }
    }
    
    /**
     * Process single job.
     */
    private function processJob(Job $job, int $workerId): void {
        $startTime = microtime(true);
        $this->metrics->incrementProcessing();
        
        if (!$this->config['quiet']) {
            echo "[Worker {$workerId}] Processing job {$job->id} ({$job->name})\n";
        }
        
        // Restore context if available and create queue_job owner
        $contextData = $job->metadata['_context'] ?? null;
        $jobOwner = null;
        
        if (Runtime::available()) {
            $parentOwnerId = $contextData['owner_id'] ?? null;
            $jobOwner = Runtime::owners()->open(
                \Nexph\Runtime\Ownership\OwnerType::QUEUE_JOB,
                $parentOwnerId ? Runtime::owners()->get($parentOwnerId)?->id() : null,
                ['job_id' => $job->id, 'job_name' => $job->name, 'worker_id' => $workerId]
            );
            
            // Track in-flight
            if (class_exists('\Nexph\Runtime\Drain\DrainController')) {
                \Nexph\Runtime\Drain\DrainController::instance()->trackInFlight($jobOwner->id());
            }
        }
        
        $restoreContext = function(callable $fn) use ($contextData, $jobOwner, $job, $workerId) {
            if ($contextData && Runtime::available()) {
                $ctx = array_merge($contextData, [
                    'job_id' => $job->id,
                    'worker_id' => $workerId,
                    'owner_id' => $jobOwner?->id()->toString(),
                    'owner_type' => 'queue_job',
                ]);
                return Runtime::withContext($ctx, $fn);
            }
            return $fn();
        };
        
        try {
            $restoreContext(function() use ($job, $workerId, $startTime) {
                try {
                    // Check drain state before executing
                    if (class_exists('\Nexph\Runtime\Drain\DrainController')) {
                        $drainController = \Nexph\Runtime\Drain\DrainController::instance();
                        if (!$drainController->isAccepting()) {
                            throw new \RuntimeException('Queue is draining, job rejected');
                        }
                    }
                    
                    $job->status = JobStatus::RUNNING;
                    $job->attempts++;
                    $job->started_at = time();
                    $this->driver->update($job);
                    
                    if (!isset($this->handlers[$job->name])) {
                        throw new \RuntimeException("No handler registered for job: {$job->name}");
                    }
                    
                    $handler = $this->handlers[$job->name];
                    $handlerInstance = null;
                    
                    if (is_string($handler) && class_exists($handler)) {
                        $handlerInstance = new $handler();
                        if (method_exists($handlerInstance, 'before')) {
                            $handlerInstance->before($job);
                        }
                    }
                    
                    $result = $this->executeWithTimeout($handlerInstance ?? $handler, $job);
                    
                    $job->status = JobStatus::COMPLETED;
                    $job->completed_at = time();
                    $job->result = $result;

                    if ($handlerInstance && method_exists($handlerInstance, 'after')) {
                        $handlerInstance->after($job, $result);
                    }

                    $this->driver->update($job);
                    $this->driver->delete($job->id);
                    
                    $duration = microtime(true) - $startTime;
                    $this->metrics->incrementCompleted($duration);
                    
                    if (!$this->config['quiet']) {
                        echo "[Worker {$workerId}] Completed job {$job->id} in " . number_format($duration, 3) . "s\n";
                    }
                    
                } catch (\Throwable $e) {
                    $this->handleJobFailure($job, $e, $workerId);
                } finally {
                    unset($result, $handlerInstance, $handler);
                }
            });
        } finally {
            if ($jobOwner) {
                // Finish in-flight tracking
                if (class_exists('\Nexph\Runtime\Drain\DrainController')) {
                    \Nexph\Runtime\Drain\DrainController::instance()->finishInFlight($jobOwner->id());
                }
                $jobOwner->close('job_completed');
            }
        }
    }
    
    /**
     * Execute handler with timeout protection.
     */
    private function executeWithTimeout(callable|string|object $handler, Job $job): mixed {
        if (is_string($handler)) {
            $handler = new $handler();
        }
        
        if (is_callable($handler)) {
            return $handler($job->payload, $job);
        }
        
        if (is_object($handler) && method_exists($handler, 'handle')) {
            return $handler->handle($job->payload, $job);
        }
        
        throw new \RuntimeException("Invalid handler for job: {$job->name}");
    }
    
    /**
     * Handle job failure.
     */
    private function handleJobFailure(Job $job, \Throwable $e, int $workerId): void {
        echo "[Worker {$workerId}] Job {$job->id} failed: {$e->getMessage()}\n";
        
        $job->error = $e->getMessage();
        $job->failed_at = time();
        
        if ($job->attempts >= $job->max_attempts) {
            $job->status = JobStatus::FAILED;
            $this->driver->update($job);
            $this->driver->pushDeadLetter($job);
            $this->metrics->incrementFailed();
            
            echo "[Worker {$workerId}] Job {$job->id} moved to dead letter queue\n";
        } else {
            $delay = $this->config['retry_delay'] * pow(2, $job->attempts - 1);
            $job->status = JobStatus::PENDING;
            $job->available_at = time() + (int)$delay;
            $this->driver->update($job);
            $this->metrics->incrementRetried();
            
            echo "[Worker {$workerId}] Job {$job->id} will retry in {$delay}s (attempt {$job->attempts}/{$job->max_attempts})\n";
        }
    }
    
    /**
     * Metrics reporting loop.
     */
    private function metricsLoop(): void {
        $lastMetrics = null;
        $idleCount = 0;
        
        while ($this->running) {
            Runtime::sleep($this->config['metrics_interval']);
            
            if (!$this->running) {
                break;
            }
            
            $status = $this->status();
            $metrics = $status['metrics'];
            
            // Only print if verbose mode OR state changed
            $currentState = [
                'depth' => $status['depth'],
                'completed' => $metrics['counters']['jobs_completed'],
                'failed' => $metrics['counters']['jobs_failed'],
            ];
            
            $stateChanged = $lastMetrics !== $currentState;
            $isActive = $status['depth'] > 0 || $metrics['counters']['jobs_processing'] > 0;
            
            if ($this->config['verbose'] || $stateChanged || $isActive) {
                echo "[Queue] Depth: {$status['depth']}, " .
                     "Completed: {$metrics['counters']['jobs_completed']}, " .
                     "Failed: {$metrics['counters']['jobs_failed']}, " .
                     "Active Workers: {$status['workers']}\n";
                $idleCount = 0;
            } else {
                $idleCount++;
                // Print idle heartbeat every 6 intervals (60s if interval is 10s)
                if ($idleCount % 6 === 0) {
                    echo "[Queue] Idle (completed: {$metrics['counters']['jobs_completed']})\n";
                }
            }
            
            $lastMetrics = $currentState;
        }
    }
    
    /**
     * Generate unique job ID.
     */
    private function generateId(): string {
        return bin2hex(random_bytes(16));
    }
}
