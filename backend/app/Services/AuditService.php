<?php

namespace App\Services;

use App\Enums\AuditFindingSeverity;
use App\Enums\AuditFindingStatus;
use App\Enums\AuditScope;
use App\Enums\TaskActor;
use App\Enums\TaskSource;
use App\Models\AuditFinding;
use App\Models\AuditRun;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuditService
{
    public function __construct(private TaskService $tasks) {}

    public function submitRun(Project $project, array $payload): AuditRun
    {
        $suppressions = $this->suppressions($project);

        return DB::transaction(function () use ($project, $payload, $suppressions) {
            $run = AuditRun::create([
                'project_id' => $project->id,
                'scope' => $payload['scope'] ?? AuditScope::SYSTEM->value,
                'feature' => $payload['feature'] ?? null,
                'status' => $payload['status'],
                'gate_reached' => $payload['gate_reached'],
                'app_classification' => $payload['app_classification'] ?? [],
                'summary' => $payload['summary'] ?? null,
                'meta' => $payload['meta'] ?? [],
                'started_at' => $payload['started_at'] ?? null,
                'finished_at' => $payload['finished_at'] ?? now(),
            ]);

            foreach ($payload['findings'] ?? [] as $finding) {
                $run->findings()->create([
                    'project_id' => $project->id,
                    'gate' => $finding['gate'],
                    'module' => $finding['module'],
                    'severity' => $finding['severity'],
                    'title' => $finding['title'],
                    'detail' => $finding['detail'] ?? null,
                    'evidence' => $finding['evidence'] ?? [],
                    'suppression_key' => $finding['suppression_key'],
                    'status' => in_array($finding['suppression_key'], $suppressions, true)
                        ? AuditFindingStatus::SUPPRESSED
                        : AuditFindingStatus::OPEN,
                ]);
            }

            return $run->load('findings');
        });
    }

    /**
     * Latest run for a project. Without $feature, returns the latest SYSTEM
     * (handover) run so a newer feature audit never hijacks the handover view.
     * With $feature, returns the latest run for that specific feature.
     */
    public function latest(Project $project, ?string $feature = null): ?AuditRun
    {
        return AuditRun::where('project_id', $project->id)
            ->when(
                $feature !== null,
                fn ($q) => $q->where('scope', AuditScope::FEATURE)->where('feature', $feature),
                fn ($q) => $q->where('scope', AuditScope::SYSTEM),
            )
            ->latest('created_at')
            ->with('findings')
            ->first();
    }

    /**
     * Distinct features that have at least one feature-audit run for this
     * project, each with its latest run's status/date + open finding count.
     * Powers the feature picker on the /audits page.
     */
    public function features(Project $project): Collection
    {
        return AuditRun::where('project_id', $project->id)
            ->where('scope', AuditScope::FEATURE)
            ->whereNotNull('feature')
            ->withCount([
                'findings as open_findings_count' => fn ($q) => $q->where('status', AuditFindingStatus::OPEN),
                'findings as critical_findings_count' => fn ($q) => $q
                    ->where('status', AuditFindingStatus::OPEN)
                    ->where('severity', AuditFindingSeverity::CRITICAL),
            ])
            ->latest('created_at')
            ->get()
            ->unique('feature')
            ->values()
            ->map(fn (AuditRun $run) => [
                'feature' => $run->feature,
                'status' => $run->status,
                'gate_reached' => $run->gate_reached,
                'open_findings_count' => $run->open_findings_count,
                'critical_findings_count' => $run->critical_findings_count,
                'last_run_at' => $run->created_at,
            ]);
    }

    /** Latest runs across ALL projects, newest first — powers the no-project landing list. */
    public function recent(int $limit = 8): Collection
    {
        return AuditRun::with('project:id,slug,name')
            ->withCount([
                'findings as open_findings_count' => fn ($q) => $q->where('status', AuditFindingStatus::OPEN),
                'findings as critical_findings_count' => fn ($q) => $q
                    ->where('status', AuditFindingStatus::OPEN)
                    ->where('severity', AuditFindingSeverity::CRITICAL),
                'findings as findings_count',
            ])
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    public function history(Project $project, ?string $feature = null): Collection
    {
        return AuditRun::where('project_id', $project->id)
            ->when(
                $feature !== null,
                fn ($q) => $q->where('scope', AuditScope::FEATURE)->where('feature', $feature),
                fn ($q) => $q->where('scope', AuditScope::SYSTEM),
            )
            ->latest('created_at')
            ->limit(50)
            ->get();
    }

    public function dismiss(AuditFinding $finding): AuditFinding
    {
        $this->assertNotConverted($finding);
        $finding->update([
            'status' => AuditFindingStatus::DISMISSED,
            'dismissed_at' => now(),
        ]);

        return $finding;
    }

    public function reopen(AuditFinding $finding): AuditFinding
    {
        $this->assertNotConverted($finding);
        $finding->update([
            'status' => AuditFindingStatus::OPEN,
            'dismissed_at' => null,
        ]);

        return $finding;
    }

    public function convertToTask(AuditFinding $finding, TaskActor $actor): Task
    {
        if ($finding->status !== AuditFindingStatus::OPEN) {
            throw ValidationException::withMessages([
                'finding' => 'Only open findings can be converted to tasks.',
            ]);
        }

        return DB::transaction(function () use ($finding, $actor) {
            $task = $this->tasks->createTask($finding->project, [
                'title' => "[audit] {$finding->title}",
                'description' => trim(($finding->detail ?? '') . "\n\nEvidence: " . json_encode($finding->evidence)),
                'priority' => $finding->severity === AuditFindingSeverity::CRITICAL ? 'high' : 'medium',
                'status' => 'todo',
                'source' => TaskSource::JARVIS,
            ], $actor);

            $finding->update([
                'status' => AuditFindingStatus::CONVERTED,
                'task_id' => $task->id,
            ]);

            return $task;
        });
    }

    private function assertNotConverted(AuditFinding $finding): void
    {
        if ($finding->status === AuditFindingStatus::CONVERTED) {
            throw ValidationException::withMessages([
                'finding' => 'Converted findings are managed via their task.',
            ]);
        }
    }

    /** @return string[] distinct suppression_keys the user has dismissed for this project */
    public function suppressions(Project $project): array
    {
        return AuditFinding::where('project_id', $project->id)
            ->where('status', AuditFindingStatus::DISMISSED)
            ->distinct()
            ->pluck('suppression_key')
            ->values()
            ->all();
    }
}
