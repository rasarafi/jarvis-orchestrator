<?php

namespace App\Services;

use App\Enums\TaskActor;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskEvent;

class TaskEventService
{
    public function record(Task $task, TaskActor $actor, TaskEventType $type, ?array $payload = null): TaskEvent
    {
        return $task->events()->create([
            'actor' => $actor,
            'event_type' => $type,
            'payload' => $payload,
        ]);
    }
}
