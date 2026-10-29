<?php

namespace App\Services;

use App\Enums\FocusSessionStatus;
use App\Enums\TaskStatus;
use App\Models\FocusSession;
use App\Models\Task;

class FocusSessionService
{
    /**
     * Start a focus session against the given task. If an open session for
     * the same task already exists, return it (idempotent).
     */
    public function start(Task $task): FocusSession
    {
        $existing = FocusSession::query()
            ->where('task_id', $task->id)
            ->open()
            ->first();

        if ($existing) {
            return $existing;
        }

        $task->update(['status' => TaskStatus::IN_PROGRESS->value]);

        return FocusSession::create([
            'task_id' => $task->id,
            'status' => FocusSessionStatus::ACTIVE->value,
            'started_at' => now(),
            'elapsed_seconds' => 0,
        ]);
    }

    public function heartbeat(FocusSession $session, int $elapsedSeconds): FocusSession
    {
        $session->update(['elapsed_seconds' => $elapsedSeconds]);
        return $session->fresh();
    }

    public function end(FocusSession $session, int $elapsedSeconds): FocusSession
    {
        $session->update([
            'status' => FocusSessionStatus::ENDED->value,
            'ended_at' => now(),
            'elapsed_seconds' => $elapsedSeconds,
        ]);
        return $session->fresh();
    }

    /**
     * The currently-open session, if any. There can only be one at a time
     * (single-user system).
     */
    public function activeSession(): ?FocusSession
    {
        return FocusSession::query()->open()->latest('started_at')->first();
    }
}
