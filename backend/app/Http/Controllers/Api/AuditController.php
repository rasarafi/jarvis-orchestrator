<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditFindingSeverity;
use App\Enums\AuditRunStatus;
use App\Enums\AuditScope;
use App\Http\Concerns\ResolvesTaskActor;
use App\Http\Controllers\Controller;
use App\Models\AuditFinding;
use App\Models\AuditRun;
use App\Models\Project;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuditController extends Controller
{
    use ResolvesTaskActor;

    public function __construct(private AuditService $audits) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project' => ['required', 'string', 'exists:projects,slug'],
            'scope' => ['nullable', Rule::enum(AuditScope::class)],
            'feature' => ['nullable', 'string', 'max:120', 'required_if:scope,feature'],
            'status' => ['required', Rule::enum(AuditRunStatus::class)],
            'gate_reached' => ['required', 'integer', 'min:0', 'max:2'],
            'app_classification' => ['nullable', 'array'],
            'summary' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date'],
            'findings' => ['present', 'array'],
            'findings.*.gate' => ['required', 'integer', 'min:0', 'max:2'],
            'findings.*.module' => ['required', 'string', 'max:100'],
            'findings.*.severity' => ['required', Rule::enum(AuditFindingSeverity::class)],
            'findings.*.title' => ['required', 'string', 'max:255'],
            'findings.*.detail' => ['nullable', 'string'],
            'findings.*.evidence' => ['nullable', 'array'],
            'findings.*.suppression_key' => ['required', 'string', 'max:255'],
        ]);

        $project = Project::where('slug', $validated['project'])->firstOrFail();
        $run = $this->audits->submitRun($project, $validated);

        return response()->json(['data' => $this->present($run)]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->audits->history($this->project($request), $this->feature($request))]);
    }

    public function features(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->audits->features($this->project($request))]);
    }

    public function recent(): JsonResponse
    {
        return response()->json(['data' => $this->audits->recent()]);
    }

    public function latest(Request $request): JsonResponse
    {
        $run = $this->audits->latest($this->project($request), $this->feature($request));

        return response()->json(['data' => $run ? $this->present($run) : null]);
    }

    public function suppressions(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->audits->suppressions($this->project($request))]);
    }

    public function dismiss(AuditFinding $finding): JsonResponse
    {
        return response()->json(['data' => $this->audits->dismiss($finding)]);
    }

    public function reopen(AuditFinding $finding): JsonResponse
    {
        return response()->json(['data' => $this->audits->reopen($finding)]);
    }

    public function convertToTask(Request $request, AuditFinding $finding): JsonResponse
    {
        $task = $this->audits->convertToTask($finding, $this->resolveActor($request));

        return response()->json(['data' => ['task' => $task, 'finding' => $finding->fresh()]]);
    }

    private function project(Request $request): Project
    {
        $request->validate(['project' => ['required', 'string', 'exists:projects,slug']]);

        return Project::where('slug', $request->query('project'))->firstOrFail();
    }

    /** Optional ?feature= query param — null selects system (handover) scope. */
    private function feature(Request $request): ?string
    {
        $feature = $request->query('feature');

        return is_string($feature) && $feature !== '' ? $feature : null;
    }

    private function present(AuditRun $run): array
    {
        return ['run' => $run->makeHidden('findings'), 'findings' => $run->findings];
    }
}
