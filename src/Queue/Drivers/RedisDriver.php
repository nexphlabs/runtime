<?php

/**
 * This file is part of the Nexph Framework.
 *
 * (c) Nexphlabs <https://github.com/nexphlabs>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Nexph\Runtime\Queue\Drivers;

use Nexph\Runtime\Queue\QueueDriver;
use Nexph\Runtime\Queue\Job;

class RedisDriver implements QueueDriver {
    private \Redis $redis;
    private string $queueKey;
    private string $processingKey;
    private string $deadLetterKey;
    private string $dataPrefix;
    private int $maxPayloadSize;
    
    public function __construct(\Redis $redis, string $prefix = 'nexph_queue', int $maxPayloadSize = 10485760) {
        $this->redis = $redis;
        $this->queueKey = $prefix . ':queue';
        $this->processingKey = $prefix . ':processing';
        $this->deadLetterKey = $prefix . ':dead_letters';
        $this->dataPrefix = $prefix . ':job:';
        $this->maxPayloadSize = $maxPayloadSize;
        
        // Track Redis connection
        if (class_exists('\Nexph\Runtime\Resource\ResourceRegistry') && class_exists('\Nexph\Runtime\Runtime') && \Nexph\Runtime\Runtime::available()) {
            \Nexph\Runtime\Resource\ResourceRegistry::instance()->track(
                $redis,
                'redis_connection',
                \Nexph\Runtime\Runtime::context()->ownerId()
            );
        }
    }
    
    public function push(Job $job): void {
        $data = json_encode($job->toArray());
        if (strlen($data) > $this->maxPayloadSize) {
            throw new \RuntimeException("Job payload exceeds max size: " . strlen($data) . " bytes");
        }
        $key = $this->dataPrefix . $job->id;
        $this->redis->set($key, $data);
        $this->redis->zAdd($this->queueKey, $job->available_at, $job->id);
    }
    
    public function pop(): ?Job {
        $now = time();
        $ids = $this->redis->zRangeByScore($this->queueKey, 0, $now, ['limit' => [0, 1]]);
        
        if (empty($ids)) {
            return null;
        }
        
        $id = $ids[0];
        $removed = $this->redis->zRem($this->queueKey, $id);
        
        if (!$removed) {
            return null;
        }
        
        $key = $this->dataPrefix . $id;
        $data = $this->redis->get($key);
        
        if (!$data) {
            return null;
        }
        
        try {
            $jobData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($jobData) || $jobData['status'] !== 'pending') {
                return null;
            }
            return Job::fromArray($jobData);
        } catch (\JsonException $e) {
            return null;
        }
    }
    
    public function update(Job $job): void {
        $key = $this->dataPrefix . $job->id;
        $data = json_encode($job->toArray());
        $this->redis->set($key, $data);
        
        if ($job->status === 'pending') {
            $this->redis->zAdd($this->queueKey, $job->available_at, $job->id);
        }
    }
    
    public function get(string $id): ?Job {
        $key = $this->dataPrefix . $id;
        $data = $this->redis->get($key);
        
        if (!$data) {
            return null;
        }
        
        try {
            $jobData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            return is_array($jobData) ? Job::fromArray($jobData) : null;
        } catch (\JsonException $e) {
            return null;
        }
    }
    
    public function delete(string $id): void {
        $key = $this->dataPrefix . $id;
        $this->redis->del($key);
        $this->redis->zRem($this->queueKey, $id);
        $this->redis->zRem($this->processingKey, $id);
    }
    
    public function depth(): int {
        $now = time();
        return (int)$this->redis->zCount($this->queueKey, 0, $now);
    }
    
    public function pushDeadLetter(Job $job): void {
        $key = $this->dataPrefix . $job->id;
        $this->redis->set($key, json_encode($job->toArray()));
        $this->redis->zAdd($this->deadLetterKey, $job->failed_at ?? time(), $job->id);
        $this->redis->zRem($this->queueKey, $job->id);
    }
    
    public function getDeadLetters(int $limit = 100): array {
        $ids = $this->redis->zRevRange($this->deadLetterKey, 0, $limit - 1);
        $jobs = [];
        
        foreach ($ids as $id) {
            $key = $this->dataPrefix . $id;
            $data = $this->redis->get($key);
            
            if ($data) {
                try {
                    $jobData = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($jobData)) {
                        $jobs[] = Job::fromArray($jobData);
                    }
                } catch (\JsonException $e) {
                    continue;
                }
            }
        }
        
        return $jobs;
    }
    
    public function clear(): void {
        $ids = $this->redis->zRange($this->queueKey, 0, -1);
        foreach ($ids as $id) {
            $this->redis->del($this->dataPrefix . $id);
        }
        
        $ids = $this->redis->zRange($this->deadLetterKey, 0, -1);
        foreach ($ids as $id) {
            $this->redis->del($this->dataPrefix . $id);
        }
        
        $this->redis->del($this->queueKey);
        $this->redis->del($this->processingKey);
        $this->redis->del($this->deadLetterKey);
    }
}
