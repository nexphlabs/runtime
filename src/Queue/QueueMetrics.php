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
 * Queue metrics tracker.
 */
class QueueMetrics {
    private int $enqueued = 0;
    private int $processing = 0;
    private int $completed = 0;
    private int $failed = 0;
    private int $retried = 0;
    private float $totalDuration = 0.0;
    private float $minDuration = PHP_FLOAT_MAX;
    private float $maxDuration = 0.0;
    private int $startTime;
    
    public function __construct() {
        $this->startTime = time();
    }
    
    public function incrementEnqueued(): void {
        $this->enqueued++;
    }
    
    public function incrementProcessing(): void {
        $this->processing++;
    }
    
    public function incrementCompleted(float $duration): void {
        $this->completed++;
        $this->processing = max(0, $this->processing - 1);
        $this->totalDuration += $duration;
        $this->minDuration = min($this->minDuration, $duration);
        $this->maxDuration = max($this->maxDuration, $duration);
    }
    
    public function incrementFailed(): void {
        $this->failed++;
        $this->processing = max(0, $this->processing - 1);
    }
    
    public function incrementRetried(): void {
        $this->retried++;
        $this->processing = max(0, $this->processing - 1);
    }
    
    public function toArray(): array {
        $avgDuration = $this->completed > 0 ? $this->totalDuration / $this->completed : 0;
        $uptime = time() - $this->startTime;
        $throughput = $uptime > 0 ? $this->completed / $uptime : 0;
        $total = $this->completed + $this->failed;
        $successRate = $total > 0 ? ($this->completed / $total) * 100 : 0;
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        
        return [
            'counters' => [
                'jobs_enqueued' => $this->enqueued,
                'jobs_processing' => $this->processing,
                'jobs_completed' => $this->completed,
                'jobs_failed' => $this->failed,
                'jobs_retried' => $this->retried,
            ],
            'timers' => [
                'avg_duration' => round($avgDuration, 3),
                'min_duration' => $this->minDuration === PHP_FLOAT_MAX ? 0 : round($this->minDuration, 3),
                'max_duration' => round($this->maxDuration, 3),
            ],
            'computed' => [
                'throughput' => round($throughput, 2),
                'success_rate' => round($successRate, 1),
                'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
            ],
            'uptime' => $uptime,
        ];
    }
}
