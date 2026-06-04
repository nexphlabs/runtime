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
use Nexph\Runtime\Worker;

/**
 * Long-running queue worker process.
 * 
 * Manages worker lifecycle, graceful shutdown, and panic recovery.
 */
class QueueWorker {
    private Queue $queue;
    private bool $shouldStop = false;
    private array $config = [];
    private int $jobsProcessed = 0;
    private int $startTime;
    private $workerOwner = null;
    
    public function __construct(Queue $queue, array $config = []) {
        $this->queue = $queue;
        $this->config = array_merge([
            'max_jobs' => 0,
            'max_time' => 0,
            'memory_limit' => 128 * 1024 * 1024,
            'sleep' => 1.0,
            'restart_on_panic' => true,
        ], $config);
        $this->startTime = time();
        
        // Create worker owner
        if (class_exists('\Nexph\Runtime\Runtime') && \Nexph\Runtime\Runtime::available()) {
            $this->workerOwner = \Nexph\Runtime\Runtime::owners()->open(
                \Nexph\Runtime\Ownership\OwnerType::WORKER,
                null,
                ['pid' => getmypid(), 'started_at' => $this->startTime]
            );
        }
    }
    
    /**
     * Start worker process.
     */
    public function start(): void {
        $this->setupSignalHandlers();
        
        echo "[QueueWorker] Starting worker (PID: " . getmypid() . ")\n";
        
        while (!$this->shouldStop) {
            try {
                $this->checkLimits();
                $this->processNextJob();
                
            } catch (\Throwable $e) {
                $this->handlePanic($e);
                
                if (!$this->config['restart_on_panic']) {
                    break;
                }
            }
        }
        
        echo "[QueueWorker] Worker stopped\n";
    }
    
    /**
     * Stop worker gracefully.
     */
    public function stop(): void {
        $this->shouldStop = true;
        
        // Close worker owner
        if ($this->workerOwner) {
            $this->workerOwner->close('worker_stopped');
        }
    }
    
    /**
     * Process next available job.
     */
    private function processNextJob(): void {
        $drain = \Nexph\Runtime\Drain\DrainController::instance();
        
        if ($drain->isDraining() || $drain->isStopped()) {
            echo "[QueueWorker] Drain active, stopping job processing\n";
            $this->stop();
            return;
        }
        
        // This will be handled by the queue's work() method
        // For now, just sleep
        if (Runtime::available()) {
            Runtime::sleep($this->config['sleep']);
        } else {
            usleep((int)($this->config['sleep'] * 1_000_000));
        }
    }
    
    /**
     * Check worker limits.
     */
    private function checkLimits(): void {
        // Max jobs limit
        if ($this->config['max_jobs'] > 0 && $this->jobsProcessed >= $this->config['max_jobs']) {
            echo "[QueueWorker] Max jobs limit reached, stopping\n";
            $this->stop();
            return;
        }
        
        // Max time limit
        if ($this->config['max_time'] > 0) {
            $elapsed = time() - $this->startTime;
            if ($elapsed >= $this->config['max_time']) {
                echo "[QueueWorker] Max time limit reached, stopping\n";
                $this->stop();
                return;
            }
        }
        
        // Memory limit
        $memoryUsage = memory_get_usage(true);
        if ($memoryUsage >= $this->config['memory_limit']) {
            echo "[QueueWorker] Memory limit reached, stopping\n";
            $this->stop();
            return;
        }
    }
    
    /**
     * Handle worker panic.
     */
    private function handlePanic(\Throwable $e): void {
        echo "[QueueWorker] PANIC: {$e->getMessage()}\n";
        echo $e->getTraceAsString() . "\n";
        
        // Log to file
        error_log("[QueueWorker] PANIC: {$e->getMessage()}");
        error_log($e->getTraceAsString());
        
        // Sleep before restart
        if (Runtime::available()) {
            Runtime::sleep(5.0);
        } else {
            sleep(5);
        }
    }
    
    /**
     * Setup signal handlers for graceful shutdown.
     */
    private function setupSignalHandlers(): void {
        if (!Runtime::available() || !function_exists('pcntl_signal')) {
            return;
        }
        
        pcntl_async_signals(true);
        
        $handler = function(int $signal) {
            echo "\n[QueueWorker] Received signal {$signal}, stopping gracefully...\n";
            $this->stop();
        };
        
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);
    }
    
    /**
     * Fork worker process.
     */
    public static function fork(Queue $queue, array $config = []): int {
        if (!function_exists('pcntl_fork')) {
            throw new \RuntimeException('pcntl_fork not available');
        }
        
        $pid = pcntl_fork();
        
        if ($pid === -1) {
            throw new \RuntimeException('Failed to fork worker process');
        }
        
        if ($pid === 0) {
            // Child process
            $worker = new self($queue, $config);
            $worker->start();
            exit(0);
        }
        
        // Parent process
        return $pid;
    }
}
