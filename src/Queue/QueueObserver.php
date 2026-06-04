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
 * Runtime observability for queue system.
 * 
 * Tracks queue depth, worker health, throughput, and system metrics.
 */
class QueueObserver {
    private Queue $queue;
    private float $lastLoopTime = 0;
    private bool $reporting = false;
    
    public function __construct(Queue $queue) {
        $this->queue = $queue;
    }
    
    /**
     * Get comprehensive queue metrics.
     */
    public function getMetrics(): array {
        $queueMetrics = $this->queue->metrics()->toArray();
        $status = $this->queue->status();
        $counters = $queueMetrics['counters'];
        $timers = $queueMetrics['timers'];
        $computed = $queueMetrics['computed'];
        
        return [
            'queue' => [
                'depth' => $status['depth'],
                'workers' => $status['workers'],
                'running' => $status['running'],
            ],
            'jobs' => [
                'enqueued' => $counters['jobs_enqueued'],
                'processing' => $counters['jobs_processing'],
                'completed' => $counters['jobs_completed'],
                'failed' => $counters['jobs_failed'],
                'retried' => $counters['jobs_retried'],
            ],
            'performance' => [
                'throughput' => $computed['throughput'],
                'avg_duration' => $timers['avg_duration'],
                'min_duration' => $timers['min_duration'],
                'max_duration' => $timers['max_duration'],
            ],
            'runtime' => [
                'uptime' => $queueMetrics['uptime'],
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'loop_lag' => $this->getLoopLag(),
            ],
        ];
    }
    
    /**
     * Get event loop lag (async mode only).
     */
    private function getLoopLag(): float {
        if (!Runtime::available()) {
            return 0.0;
        }
        
        $now = microtime(true);
        $lag = $this->lastLoopTime > 0 ? $now - $this->lastLoopTime : 0;
        $this->lastLoopTime = $now;
        
        return $lag;
    }
    
    /**
     * Print metrics to console.
     */
    public function printMetrics(): void {
        $metrics = $this->getMetrics();
        
        echo "\n=== Queue Metrics ===\n";
        echo "Queue Depth: {$metrics['queue']['depth']}\n";
        echo "Active Workers: {$metrics['queue']['workers']}\n";
        echo "Jobs Completed: {$metrics['jobs']['completed']}\n";
        echo "Jobs Failed: {$metrics['jobs']['failed']}\n";
        echo "Throughput: {$metrics['performance']['throughput']} jobs/sec\n";
        echo "Avg Duration: {$metrics['performance']['avg_duration']}s\n";
        echo "Memory Usage: " . $this->formatBytes($metrics['runtime']['memory_usage']) . "\n";
        echo "Uptime: {$metrics['runtime']['uptime']}s\n";
        echo "====================\n\n";
    }
    
    /**
     * Start periodic metrics reporting.
     */
    public function startReporting(int $interval = 10): void {
        if ($this->reporting) {
            return;
        }

        $this->reporting = true;

        if (Runtime::available()) {
            Runtime::spawn(function() use ($interval) {
                while ($this->reporting) {
                    Runtime::sleep($interval);
                    if (!$this->reporting) {
                        break;
                    }
                    $this->printMetrics();
                }
            });
        }
    }

    public function stopReporting(): void {
        $this->reporting = false;
    }
    
    /**
     * Format bytes to human readable.
     */
    private function formatBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
