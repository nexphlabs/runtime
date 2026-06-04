<?php

namespace Nexph\Runtime\Queue\Drivers;

use Nexph\Runtime\Queue\Job;
use Nexph\Runtime\Queue\QueueDriver;

class ApcuRingDriver implements QueueDriver {
    private string $prefix;
    private int $maxPayloadSize;
    private int $scanLimit;

    public function __construct(string $prefix = 'nexph_queue_ring', int $maxPayloadSize = 10485760, int $scanLimit = 1024) {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new \RuntimeException('APCu extension not available');
        }

        $this->prefix = $prefix;
        $this->maxPayloadSize = $maxPayloadSize;
        $this->scanLimit = max(1, $scanLimit);
        $this->seed($this->tailKey());
        $this->seed($this->headKey());
        apcu_add($this->deadLetterKey(), []);
    }

    public function push(Job $job): void {
        $data = json_encode($job->toArray());
        if ($data === false || strlen($data) > $this->maxPayloadSize) {
            throw new \RuntimeException('Job payload exceeds max size');
        }

        apcu_store($this->jobKey($job->id), $data);
        $seq = apcu_inc($this->tailKey(), 1, $ok);
        if (!$ok) {
            $this->seed($this->tailKey());
            $seq = apcu_inc($this->tailKey(), 1);
        }
        apcu_store($this->slotKey((int) $seq), $job->id);
    }

    public function pop(): ?Job {
        $tail = (int) (apcu_fetch($this->tailKey()) ?: 0);
        for ($i = 0; $i < $this->scanLimit; $i++) {
            $seq = apcu_inc($this->headKey(), 1, $ok);
            if (!$ok) {
                $this->seed($this->headKey());
                $seq = apcu_inc($this->headKey(), 1);
            }
            if ($seq > $tail) {
                apcu_dec($this->headKey(), 1);
                return null;
            }

            $id = apcu_fetch($this->slotKey((int) $seq), $slotOk);
            apcu_delete($this->slotKey((int) $seq));
            if (!$slotOk || !is_string($id)) {
                continue;
            }

            $job = $this->get($id);
            if ($job === null || $job->status !== 'pending') {
                continue;
            }
            if ($job->available_at > time()) {
                $this->push($job);
                continue;
            }

            $job->status = 'reserved';
            $this->update($job);
            return $job;
        }

        return null;
    }

    public function update(Job $job): void {
        $data = json_encode($job->toArray());
        if ($data !== false) {
            apcu_store($this->jobKey($job->id), $data);
        }
    }

    public function get(string $id): ?Job {
        $data = apcu_fetch($this->jobKey($id));
        if (!is_string($data) || $data === '') {
            return null;
        }

        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? Job::fromArray($decoded) : null;
        } catch (\JsonException) {
            apcu_delete($this->jobKey($id));
            return null;
        }
    }

    public function delete(string $id): void {
        apcu_delete($this->jobKey($id));
    }

    public function depth(): int {
        $tail = (int) (apcu_fetch($this->tailKey()) ?: 0);
        $head = (int) (apcu_fetch($this->headKey()) ?: 0);
        return max(0, $tail - $head);
    }

    public function pushDeadLetter(Job $job): void {
        $job->failed_at ??= time();
        $this->update($job);
        $dead = apcu_fetch($this->deadLetterKey()) ?: [];
        $dead[$job->id] = $job->failed_at;
        apcu_store($this->deadLetterKey(), $dead);
    }

    public function getDeadLetters(int $limit = 100): array {
        $dead = apcu_fetch($this->deadLetterKey()) ?: [];
        arsort($dead);
        $jobs = [];
        foreach (array_slice(array_keys($dead), 0, $limit) as $id) {
            $job = $this->get((string) $id);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }
        return $jobs;
    }

    public function clear(): void {
        $tail = (int) (apcu_fetch($this->tailKey()) ?: 0);
        $head = (int) (apcu_fetch($this->headKey()) ?: 0);
        for ($seq = $head + 1; $seq <= $tail; $seq++) {
            $id = apcu_fetch($this->slotKey($seq));
            if (is_string($id)) {
                apcu_delete($this->jobKey($id));
            }
            apcu_delete($this->slotKey($seq));
        }

        foreach (array_keys(apcu_fetch($this->deadLetterKey()) ?: []) as $id) {
            apcu_delete($this->jobKey((string) $id));
        }
        apcu_store($this->tailKey(), 0);
        apcu_store($this->headKey(), 0);
        apcu_store($this->deadLetterKey(), []);
    }

    private function seed(string $key): void {
        apcu_add($key, 0);
    }

    private function tailKey(): string {
        return $this->prefix . ':tail';
    }

    private function headKey(): string {
        return $this->prefix . ':head';
    }

    private function deadLetterKey(): string {
        return $this->prefix . ':dead';
    }

    private function slotKey(int $seq): string {
        return $this->prefix . ':slot:' . $seq;
    }

    private function jobKey(string $id): string {
        return $this->prefix . ':job:' . $id;
    }
}
