<?php

namespace Nexph\Runtime\EventLoop;

class EvEventLoop implements EventLoopInterface
{
    public function __construct()
    {
        if (!extension_loaded('ev')) {
            throw new \RuntimeException('ext-ev is not available');
        }
        throw new \RuntimeException('EvEventLoop not yet implemented');
    }

    public function onReadable($stream, callable $callback): void
    {
    }

    public function removeReadable($stream): void
    {
    }

    public function onWritable($stream, callable $callback): void
    {
    }

    public function removeWritable($stream): void
    {
    }

    public function timer(float $seconds, callable $callback, bool $repeat = false): int
    {
        return 0;
    }

    public function cancelTimer(int $id): void
    {
    }

    public function run(): void
    {
    }

    public function stop(): void
    {
    }

    public function isRunning(): bool
    {
        return false;
    }
}
