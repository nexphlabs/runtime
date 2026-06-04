<?php

declare(strict_types=1);

namespace Nexph\Runtime\Ownership;

/**
 * Types of runtime owners
 */
enum OwnerType: string
{
    case SYSTEM = 'system';
    case REQUEST = 'request';
    case FIBER = 'fiber';
    case QUEUE_JOB = 'queue_job';
    case TIMER = 'timer';
    case WORKER = 'worker';
    case SCHEDULER_TASK = 'scheduler_task';
    case RESOURCE = 'resource';
    case EXECUTOR_TASK = 'executor_task';
}
