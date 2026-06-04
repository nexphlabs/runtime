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

/**
 * Queue manager for managing multiple queues and workers.
 */
class QueueManager {
    private array $queues = [];
    private bool $running = false;
    
    /**
     * Register a queue.
     */
    public function addQueue(string $name, Queue $queue): void {
        $this->queues[$name] = $queue;
    }
    
    /**
     * Get queue by name.
     */
    public function queue(string $name = 'default'): Queue {
        if (!isset($this->queues[$name])) {
            throw new \RuntimeException("Queue not found: {$name}");
        }
        
        return $this->queues[$name];
    }
    
    /**
     * Start all queue workers.
     */
    public function startAll(): void {
        if ($this->running) {
            return;
        }
        
        $this->running = true;
        
        if (Runtime::available()) {
            $this->startAllAsync();
        } else {
            $this->startAllSync();
        }
    }
    
    /**
     * Stop all queue workers.
     */
    public function stopAll(): void {
        $this->running = false;
        
        foreach ($this->queues as $queue) {
            $queue->stop();
        }
    }
    
    /**
     * Get status of all queues.
     */
    public function status(): array {
        $status = [];
        
        foreach ($this->queues as $name => $queue) {
            $status[$name] = $queue->status();
        }
        
        return $status;
    }
    
    /**
     * Start all queues in async mode.
     */
    private function startAllAsync(): void {
        foreach ($this->queues as $name => $queue) {
            Runtime::spawn(function() use ($name, $queue) {
                echo "[QueueManager] Starting queue: {$name}\n";
                $queue->work();
            });
        }
        
        Runtime::run();
    }
    
    /**
     * Start all queues in sync mode (round-robin).
     */
    private function startAllSync(): void {
        while ($this->running) {
            $hasWork = false;
            
            foreach ($this->queues as $name => $queue) {
                if ($queue->workOnce(1)) {
                    $hasWork = true;
                }
            }
            
            if (!$hasWork) {
                usleep(1_000_000);
            }
        }
    }
}
