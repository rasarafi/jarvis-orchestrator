<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectGroup;
use App\Enums\RecapModel;
use App\Http\Concerns\ResolvesTaskActor;
use App\Http\Controllers\Controller;
use App\Models\SweepMatch;
use App\Models\SweepRun;
use App\Services\CommitSweepService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SweepController extends Controller
{
    use ResolvesTaskActor;

    public function __construct(private CommitSweepService $sweep) {}

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'group' => ['nullable', Rule::enum(ProjectGroup::class)],
            'project' => ['nullable', 'string', 'exists:projects,slug'],
            'window_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'model' => ['nullable', Rule::enum(RecapModel::class)],
        ]);

        $run = $this->sweep->run(
            $validated['group'] ?? null,
            $validated['project'] ?? null,
            $validated['window_days'] ?? 7,
            RecapModel::tryFrom($validated['model'] ?? '') ?? RecapModel::HAIKU,
        );

        return response()->json(['data' => $this->present($run)]);
    }

    public function latest(): JsonResponse
    {
        $run = $this->sweep->latest();

        return response()->json(['data' => $run ? $this->present($run) : null]);
    }

    public function confirm(Request $request, SweepMatch $match): JsonResponse
    {
        $confirmed = $this->sweep->confirm($match, $this->resolveActor($request));

        return response()->json(['data' => $confirmed->load('task.project')]);
    }

    public function dismiss(SweepMatch $match): JsonResponse
    {
        return response()->json(['data' => $this->sweep->dismiss($match)->load('task.project')]);
    }

    public function undo(Request $request, SweepMatch $match): JsonResponse
    {
        $undone = $this->sweep->undo($match, $this->resolveActor($request));

        return response()->json(['data' => $undone->load('task.project')]);
    }

    private function present(SweepRun $run): array
    {
        return [
            'run' => $run->makeHidden('matches'),
            'matches' => $run->matches,
        ];
    }
}
