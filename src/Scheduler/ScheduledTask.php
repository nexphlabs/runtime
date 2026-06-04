<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime\Scheduler;

/**
 * Scheduled task representation.
 */
class ScheduledTask {
    public int $id;
    public ?string $cronExpression;
    public $callback;
    public ?int $interval = null;
    public bool $enabled = true;
    public bool $async = true;
    public ?int $lastRun = null;
    public int $runCount = 0;
    public ?float $lastDuration = null;
    public ?string $lastError = null;
    public int $errorCount = 0;
    public bool $isRunning = false;
    public int $skippedCount = 0;
    public $onError = null;
    public string $name = '';
    public string $description = '';
    private ?int $lastMinute = null;
    
    public function __construct(int $id, ?string $cronExpression, callable $callback) {
        $this->id = $id;
        $this->cronExpression = $cronExpression;
        $this->callback = $callback;
    }
    
    /**
     * Set task name.
     */
    public function name(string $name): self {
        $this->name = $name;
        return $this;
    }
    
    /**
     * Set task description.
     */
    public function description(string $description): self {
        $this->description = $description;
        return $this;
    }
    
    /**
     * Disable task.
     */
    public function disable(): self {
        $this->enabled = false;
        return $this;
    }
    
    /**
     * Enable task.
     */
    public function enable(): self {
        $this->enabled = true;
        return $this;
    }
    
    /**
     * Run task synchronously.
     */
    public function sync(): self {
        $this->async = false;
        return $this;
    }
    
    /**
     * Set error handler.
     */
    public function onError(callable $callback): self {
        $this->onError = $callback;
        return $this;
    }
    
    /**
     * Check if task should run at given time.
     */
    public function shouldRun(int $timestamp): bool {
        if (!$this->enabled) {
            return false;
        }
        
        if ($this->interval !== null) {
            if ($this->lastRun === null) {
                return true;
            }
            return ($timestamp - $this->lastRun) >= $this->interval;
        }
        
        if ($this->cronExpression === null) {
            return false;
        }
        
        $minute = (int)date('i', $timestamp);
        
        if ($this->lastMinute === $minute) {
            return false;
        }
        
        return $this->matchesCron($timestamp);
    }
    
    /**
     * Mark task as ran.
     */
    public function markRan(int $timestamp): void {
        $this->lastMinute = (int)date('i', $timestamp);
    }
    
    /**
     * Check if timestamp matches cron expression.
     */
    private function matchesCron(int $timestamp): bool {
        $parts = explode(' ', $this->cronExpression);
        
        if (count($parts) !== 5) {
            return false;
        }
        
        [$minute, $hour, $day, $month, $dayOfWeek] = $parts;
        
        $currentMinute = (int)date('i', $timestamp);
        $currentHour = (int)date('G', $timestamp);
        $currentDay = (int)date('j', $timestamp);
        $currentMonth = (int)date('n', $timestamp);
        $currentDayOfWeek = (int)date('w', $timestamp);
        
        if (!$this->matchesCronPart($minute, $currentMinute, 0, 59)) {
            return false;
        }
        
        if (!$this->matchesCronPart($hour, $currentHour, 0, 23)) {
            return false;
        }
        
        if (!$this->matchesCronPart($day, $currentDay, 1, 31)) {
            return false;
        }
        
        if (!$this->matchesCronPart($month, $currentMonth, 1, 12)) {
            return false;
        }
        
        if (!$this->matchesCronPart($dayOfWeek, $currentDayOfWeek, 0, 6)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if value matches cron part.
     */
    private function matchesCronPart(string $part, int $value, int $min, int $max): bool {
        if ($part === '*') {
            return true;
        }
        
        if (str_contains($part, '/')) {
            [$range, $step] = explode('/', $part);
            $step = (int)$step;
            
            if ($range === '*') {
                return $value % $step === 0;
            }
            
            if (str_contains($range, '-')) {
                [$start, $end] = explode('-', $range);
                $start = (int)$start;
                $end = (int)$end;
                
                if ($value < $start || $value > $end) {
                    return false;
                }
                
                return ($value - $start) % $step === 0;
            }
        }
        
        if (str_contains($part, '-')) {
            [$start, $end] = explode('-', $part);
            return $value >= (int)$start && $value <= (int)$end;
        }
        
        if (str_contains($part, ',')) {
            $values = array_map('intval', explode(',', $part));
            return in_array($value, $values);
        }
        
        return $value === (int)$part;
    }
    
    /**
     * Get task info.
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'cron_expression' => $this->cronExpression,
            'interval' => $this->interval,
            'enabled' => $this->enabled,
            'async' => $this->async,
            'last_run' => $this->lastRun,
            'run_count' => $this->runCount,
            'last_duration' => $this->lastDuration,
            'last_error' => $this->lastError,
            'error_count' => $this->errorCount,
            'is_running' => $this->isRunning,
            'skipped_count' => $this->skippedCount,
        ];
    }
}
