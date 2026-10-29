<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;

class ActivityService
{
    public function log(
        string $type,
        string $message,
        ?Project $project = null,
        ?array $metadata = null
    ): ActivityLog {
        return ActivityLog::create([
            'project_id' => $project?->id,
            'type' => $type,
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }

    public function logCommit(Project $project, string $message, array $metadata = []): ActivityLog
    {
        return $this->log('commit', $message, $project, $metadata);
    }

    public function logSessionStart(Project $project, string $sessionId): ActivityLog
    {
        return $this->log('session_start', 'Session started', $project, [
            'session_id' => $sessionId,
        ]);
    }

    public function logSessionEnd(Project $project, string $sessionId): ActivityLog
    {
        return $this->log('session_end', 'Session ended', $project, [
            'session_id' => $sessionId,
        ]);
    }

    public function logSync(Project $project, string $summary): ActivityLog
    {
        return $this->log('sync', $summary, $project);
    }

    public function logSystem(string $message, ?array $metadata = null): ActivityLog
    {
        return $this->log('system', $message, null, $metadata);
    }

    public function getRecentActivity(int $limit = 50)
    {
        return ActivityLog::with('project')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getProjectActivity(Project $project, int $limit = 50)
    {
        return ActivityLog::where('project_id', $project->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
