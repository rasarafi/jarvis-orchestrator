<?php

namespace App\Services;

use App\Enums\InterruptStatus;
use App\Models\Interrupt;
use App\Models\Project;
use Illuminate\Support\Collection;

class InterruptService
{
    /**
     * Create an interrupt for a project.
     * Enforces one active interrupt per project — auto-resolves any existing.
     */
    public function createInterrupt(Project $project, array $data): Interrupt
    {
        // Auto-resolve any existing active interrupt for this project
        Interrupt::where('project_id', $project->id)
            ->where('status', InterruptStatus::ACTIVE->value)
            ->update([
                'status' => InterruptStatus::RESOLVED->value,
                'resolved_at' => now(),
            ]);

        return Interrupt::create([
            'project_id' => $project->id,
            'was_working_on' => $data['was_working_on'],
            'progress' => $data['progress'] ?? '',
            'resume_instructions' => $data['resume_instructions'] ?? '',
            'status' => InterruptStatus::ACTIVE->value,
        ]);
    }

    /**
     * Get active interrupts, optionally filtered by project.
     */
    public function getActiveInterrupts(?Project $project = null): Collection
    {
        $query = Interrupt::where('status', InterruptStatus::ACTIVE->value)
            ->with('project');

        if ($project) {
            $query->where('project_id', $project->id);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Resolve an interrupt.
     */
    public function resolveInterrupt(Interrupt $interrupt): Interrupt
    {
        $interrupt->update([
            'status' => InterruptStatus::RESOLVED->value,
            'resolved_at' => now(),
        ]);

        return $interrupt->fresh();
    }
}
