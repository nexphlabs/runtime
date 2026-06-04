<?php
namespace Nexph\Runtime;

class TaskScheduler
{
    private array $tasks = [];

    public function schedule(callable $task): void
    {
        $this->tasks[] = $task;
    }

    public function run(): void
    {
        foreach ($this->tasks as $task) {
            $task();
        }
    }
}
