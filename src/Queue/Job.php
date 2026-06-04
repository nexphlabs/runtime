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
 * Job data structure.
 */
class Job {
    public string $id;
    public string $name;
    public array $payload;
    public string $status;
    public int $attempts;
    public int $max_attempts;
    public int $timeout;
    public int $created_at;
    public int $available_at;
    public ?int $started_at = null;
    public ?int $completed_at = null;
    public ?int $failed_at = null;
    public ?string $error = null;
    public mixed $result = null;
    public array $metadata = [];
    
    public function __construct(array $data) {
        $this->id = $data['id'];
        $this->name = $data['name'];
        $this->payload = $data['payload'] ?? [];
        $this->status = $data['status'];
        $this->attempts = $data['attempts'] ?? 0;
        $this->max_attempts = $data['max_attempts'] ?? 3;
        $this->timeout = $data['timeout'] ?? 300;
        $this->created_at = $data['created_at'];
        $this->available_at = $data['available_at'];
        $this->started_at = $data['started_at'] ?? null;
        $this->completed_at = $data['completed_at'] ?? null;
        $this->failed_at = $data['failed_at'] ?? null;
        $this->error = $data['error'] ?? null;
        $this->result = $data['result'] ?? null;
        $this->metadata = $data['metadata'] ?? [];
    }
    
    /**
     * Convert to array.
     */
    public function toArray(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'payload' => $this->payload,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'max_attempts' => $this->max_attempts,
            'timeout' => $this->timeout,
            'created_at' => $this->created_at,
            'available_at' => $this->available_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'failed_at' => $this->failed_at,
            'error' => $this->error,
            'result' => $this->result,
            'metadata' => $this->metadata,
        ];
    }
    
    /**
     * Create from array.
     */
    public static function fromArray(array $data): self {
        return new self($data);
    }
}
