<?php

namespace App\Services;

use App\Enums\WorkSessionStatus;
use App\Models\Project;
use App\Models\WorkSession;

class WorkSessionService
{
    public function __construct(private MemoryService $memoryService)
    {
    }
    /**
     * Start a new work session for a project
     * Automatically closes any stale active sessions first
     */
    public function startSession(Project $project, string $sessionId): WorkSession
    {
        // Close any stale active sessions first
        $this->closeStaleSessionsForProject($project);

        // Create new session with CC session ID for cross-system linking
        return WorkSession::create([
            'project_id' => $project->id,
            'cc_session_id' => $sessionId,
            'status' => WorkSessionStatus::ACTIVE->value,
            'started_at' => now(),
            'summary' => "Session: {$sessionId}",
        ]);
    }

    /**
     * End the current active session for a project
     */
    public function endSession(Project $project, ?string $sessionId = null): ?WorkSession
    {
        $session = $this->getActiveSession($project);

        if (!$session) {
            return null;
        }

        $session->update([
            'status' => WorkSessionStatus::COMPLETED->value,
            'ended_at' => now(),
        ]);

        $session = $session->fresh();

        // Index to Pinecone if session has a real summary
        if ($session && $session->summary) {
            try {
                $this->memoryService->indexWorkSession($session->load('project'));
            } catch (\Throwable $e) {
                // Fire-and-forget
            }
        }

        return $session;
    }

    /**
     * Close all active sessions for a project
     * Marks them as abandoned
     * Returns count of sessions closed
     */
    public function closeStaleSessionsForProject(Project $project): int
    {
        return WorkSession::forProject($project)
            ->active()
            ->update([
                'status' => WorkSessionStatus::ABANDONED->value,
                'ended_at' => now(),
            ]);
    }

    /**
     * Get the current active session for a project
     */
    public function getActiveSession(Project $project): ?WorkSession
    {
        return WorkSession::forProject($project)
            ->active()
            ->latest('started_at')
            ->first();
    }

    /**
     * Update session summary fields (summary, decisions, tasks)
     */
    public function updateSessionSummary(WorkSession $session, array $data): WorkSession
    {
        $session->update([
            'summary' => $data['summary'] ?? $session->summary,
            'decisions' => $data['decisions'] ?? $session->decisions,
            'tasks_done' => $data['tasks_done'] ?? $session->tasks_done,
            'tasks_next' => $data['tasks_next'] ?? $session->tasks_next,
        ]);

        return $session->fresh();
    }
}
