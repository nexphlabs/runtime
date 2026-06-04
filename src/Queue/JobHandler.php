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

/**
 * Base job handler class.
 * 
 * Extend this to create job handlers with lifecycle hooks.
 */
abstract class JobHandler {
    /**
     * Handle the job.
     */
    abstract public function handle(array $payload, Job $job): mixed;
    
    /**
     * Called before job execution.
     */
    public function before(Job $job): void {
        // Override in subclass
    }
    
    /**
     * Called after successful job execution.
     */
    public function after(Job $job, mixed $result): void {
        // Override in subclass
    }
    
    /**
     * Called when job fails.
     */
    public function failed(Job $job, \Throwable $e): void {
        // Override in subclass
    }
    
    /**
     * Determine if job should be retried.
     */
    public function shouldRetry(Job $job, \Throwable $e): bool {
        return $job->attempts < $job->max_attempts;
    }
    
    /**
     * Calculate retry delay in seconds.
     */
    public function retryDelay(Job $job): int {
        return 60 * pow(2, $job->attempts - 1);
    }
}
