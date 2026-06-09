<?php

namespace Nexph\Runtime;

class BoundedExecutor
{
    private int $maxConcurrency;
    private int $running = 0;
    private array $queue = [];

    public function __construct(int $maxConcurrency)
    {
        $this->maxConcurrency = $maxConcurrency;
    }

    public function submit(callable $task): void
    {
        if ($this->running < $this->maxConcurrency) {
            $this->run($task);
        } else {
            $this->queue[] = $task;
        }
    }

    private function run(callable $task): void
    {
        $this->running++;
        try {
            $task();
        } finally {
            $this->running--;
            $this->processQueue();
        }
    }

    private function processQueue(): void
    {
        if (!empty($this->queue) && $this->running < $this->maxConcurrency) {
            $task = array_shift($this->queue);
            $this->run($task);
        }
    }
}
