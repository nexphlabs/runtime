<?php

namespace Nexph\Runtime\EventLoop;

use Nexph\Core\Context\ContextStore;

class SelectEventLoop implements EventLoopInterface
{
    private array $timers = [];
    private \SplPriorityQueue $timerHeap;
    private bool $running = false;
    private int $nextTimerId = 1;
    private array $readStreams = [];
    private array $readCallbacks = [];
    private array $writeStreams = [];
    private array $writeCallbacks = [];

    public function __construct()
    {
        $this->timerHeap = new \SplPriorityQueue();
        $this->timerHeap->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
    }

    public function onReadable($stream, callable $callback): void
    {
        $id = (int) $stream;
        $this->readStreams[$id] = $stream;
        $this->readCallbacks[$id] = $callback;
    }

    public function removeReadable($stream): void
    {
        $id = (int) $stream;
        unset($this->readStreams[$id], $this->readCallbacks[$id]);
    }

    public function onWritable($stream, callable $callback): void
    {
        $id = (int) $stream;
        $this->writeStreams[$id] = $stream;
        $this->writeCallbacks[$id] = $callback;
    }

    public function removeWritable($stream): void
    {
        $id = (int) $stream;
        unset($this->writeStreams[$id], $this->writeCallbacks[$id]);
    }

    public function timer(float $seconds, callable $callback, bool $repeat = false): int
    {
        $context = ContextStore::instance()->current();
        $parentOwnerId = $context->ownerId();
        
        $timerOwner = class_exists('\Nexph\Runtime\Runtime') && \Nexph\Runtime\Runtime::available()
            ? \Nexph\Runtime\Runtime::owners()->open(
                \Nexph\Core\Ownership\OwnerType::TIMER,
                $parentOwnerId ? \Nexph\Runtime\Runtime::owners()->get($parentOwnerId)?->id() : null,
                ['interval' => $seconds, 'repeat' => $repeat]
            )
            : null;
        
        $id = $this->nextTimerId++;
        $next = microtime(true) + $seconds;
        $this->timers[$id] = [
            'callback' => $callback,
            'interval' => $seconds,
            'next' => $next,
            'repeat' => $repeat,
            'context' => $context,
            'owner' => $timerOwner,
        ];
        $this->timerHeap->insert($id, -$next);
        
        if (class_exists('\Nexph\Core\Resource\ResourceRegistry') && $timerOwner) {
            \Nexph\Core\Resource\ResourceRegistry::instance()->track(
                (object)['timer_id' => $id],
                'timer',
                $timerOwner->id()
            );
        }
        
        return $id;
    }

    public function cancelTimer(int $id): void
    {
        if (isset($this->timers[$id])) {
            $timer = $this->timers[$id];
            if ($timer['owner'] ?? null) {
                $timer['owner']->close('timer_cancelled');
            }
        }
        unset($this->timers[$id]);
    }

    public function run(): void
    {
        if ($this->running) {
            throw new \RuntimeException('Event loop is already running');
        }

        $this->running = true;

        while ($this->running && $this->hasWork()) {
            $this->tick();
        }

        $this->running = false;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    private function tick(): void
    {
        $now = microtime(true);
        $this->processTimers($now);
        $this->pollIO();

        if (empty($this->readStreams) && empty($this->writeStreams)) {
            $nextWake = $this->nextWakeTime();
            $sleepTime = $nextWake !== null ? max(0, $nextWake - microtime(true)) : 0;
            if ($sleepTime > 0) {
                usleep((int) min($sleepTime * 1_000_000, 10_000));
            }
        }
    }

    private function processTimers(float $now): void
    {
        while (!$this->timerHeap->isEmpty()) {
            $id = $this->timerHeap->top();

            if (!isset($this->timers[$id])) {
                $this->timerHeap->extract();
                continue;
            }

            $timer = $this->timers[$id];
            if ($timer['next'] > $now) {
                break;
            }

            $this->timerHeap->extract();
            
            if (class_exists('\Nexph\Core\Drain\DrainController')) {
                $drainController = \Nexph\Core\Drain\DrainController::instance();
                if (!$drainController->isAccepting()) {
                    if ($timer['owner'] ?? null) {
                        $timer['owner']->close('timer_skipped_drain');
                    }
                    unset($this->timers[$id]);
                    continue;
                }
                
                $token = $drainController->cancellationToken();
                if ($token && $token->isCancelled()) {
                    if ($timer['owner'] ?? null) {
                        $timer['owner']->close('timer_cancelled_drain');
                    }
                    unset($this->timers[$id]);
                    continue;
                }
            }
            
            $context = $timer['context'] ?? null;
            if ($context !== null) {
                $store = ContextStore::instance();
                $store->runWith($context, $timer['callback']);
            } else {
                ($timer['callback'])();
            }

            if (!isset($this->timers[$id])) {
                continue;
            }

            if ($timer['repeat']) {
                $next = $now + $timer['interval'];
                $this->timers[$id]['next'] = $next;
                $this->timerHeap->insert($id, -$next);
            } else {
                if ($timer['owner'] ?? null) {
                    $timer['owner']->close('timer_completed');
                }
                unset($this->timers[$id]);
            }
        }
    }

    private function pollIO(): void
    {
        if (empty($this->readStreams) && empty($this->writeStreams)) {
            return;
        }

        $read = array_values($this->readStreams);
        $write = array_values($this->writeStreams);
        $except = null;
        $tvSec = 0;
        $tvUsec = 10000;

        if (empty($read) && empty($write)) {
            return;
        }

        $result = @stream_select($read, $write, $except, $tvSec, $tvUsec);
        if ($result === false || $result === 0) {
            return;
        }

        foreach ($read as $stream) {
            $id = (int) $stream;
            if (isset($this->readCallbacks[$id])) {
                ($this->readCallbacks[$id])($stream);
            }
        }

        foreach ($write as $stream) {
            $id = (int) $stream;
            if (isset($this->writeCallbacks[$id])) {
                ($this->writeCallbacks[$id])($stream);
            }
        }
    }

    private function nextWakeTime(): ?float
    {
        $next = null;

        while (!$this->timerHeap->isEmpty()) {
            $id = $this->timerHeap->top();
            if (!isset($this->timers[$id])) {
                $this->timerHeap->extract();
                continue;
            }
            $timerNext = $this->timers[$id]['next'];
            $next = $next === null ? $timerNext : min($next, $timerNext);
            break;
        }

        return $next;
    }

    private function hasWork(): bool
    {
        return !empty($this->timers)
            || !empty($this->readStreams)
            || !empty($this->writeStreams);
    }
}
