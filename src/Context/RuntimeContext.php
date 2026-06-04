<?php

declare(strict_types=1);

namespace Nexph\Runtime\Context;

/**
 * Immutable runtime context for distributed tracing, ownership tracking, and metadata propagation
 */
final class RuntimeContext
{
    private function __construct(
        private readonly ?string $traceId,
        private readonly ?string $spanId,
        private readonly ?string $parentSpanId,
        private readonly ?string $ownerId,
        private readonly ?string $ownerType,
        private readonly ?string $parentOwnerId,
        private readonly ?string $requestId,
        private readonly ?string $jobId,
        private readonly ?string $workerId,
        private readonly ?string $tenantId,
        private readonly ?string $userId,
        private readonly ?float $deadlineAt,
        private readonly array $metadata,
    ) {
    }

    public static function create(array $data = []): self
    {
        return new self(
            traceId: $data['trace_id'] ?? null,
            spanId: $data['span_id'] ?? null,
            parentSpanId: $data['parent_span_id'] ?? null,
            ownerId: $data['owner_id'] ?? null,
            ownerType: $data['owner_type'] ?? null,
            parentOwnerId: $data['parent_owner_id'] ?? null,
            requestId: $data['request_id'] ?? null,
            jobId: $data['job_id'] ?? null,
            workerId: $data['worker_id'] ?? null,
            tenantId: $data['tenant_id'] ?? null,
            userId: $data['user_id'] ?? null,
            deadlineAt: $data['deadline_at'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }

    public function with(array $changes): self
    {
        return new self(
            traceId: $changes['trace_id'] ?? $this->traceId,
            spanId: $changes['span_id'] ?? $this->spanId,
            parentSpanId: $changes['parent_span_id'] ?? $this->parentSpanId,
            ownerId: $changes['owner_id'] ?? $this->ownerId,
            ownerType: $changes['owner_type'] ?? $this->ownerType,
            parentOwnerId: $changes['parent_owner_id'] ?? $this->parentOwnerId,
            requestId: $changes['request_id'] ?? $this->requestId,
            jobId: $changes['job_id'] ?? $this->jobId,
            workerId: $changes['worker_id'] ?? $this->workerId,
            tenantId: $changes['tenant_id'] ?? $this->tenantId,
            userId: $changes['user_id'] ?? $this->userId,
            deadlineAt: $changes['deadline_at'] ?? $this->deadlineAt,
            metadata: isset($changes['metadata']) 
                ? array_merge($this->metadata, $changes['metadata']) 
                : $this->metadata,
        );
    }

    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'owner_id' => $this->ownerId,
            'owner_type' => $this->ownerType,
            'parent_owner_id' => $this->parentOwnerId,
            'request_id' => $this->requestId,
            'job_id' => $this->jobId,
            'worker_id' => $this->workerId,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'deadline_at' => $this->deadlineAt,
            'metadata' => $this->metadata,
        ];
    }

    public function traceId(): ?string
    {
        return $this->traceId;
    }

    public function spanId(): ?string
    {
        return $this->spanId;
    }

    public function parentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function ownerId(): ?string
    {
        return $this->ownerId;
    }

    public function ownerType(): ?string
    {
        return $this->ownerType;
    }

    public function parentOwnerId(): ?string
    {
        return $this->parentOwnerId;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function jobId(): ?string
    {
        return $this->jobId;
    }

    public function workerId(): ?string
    {
        return $this->workerId;
    }

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function deadlineAt(): ?float
    {
        return $this->deadlineAt;
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
