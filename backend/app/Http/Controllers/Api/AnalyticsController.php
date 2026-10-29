<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectGroup;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\CostAnalyticsService;
use App\Services\HealthService;
use App\Services\QuotaUtilizationService;
use App\Services\QuotaWindowService;
use App\Services\SessionAccountService;
use App\Services\SessionAnalyticsService;
use App\Services\SystemResourceService;
use App\Services\TaskAnalyticsService;
use App\Services\UsageBehaviorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function __construct(
        private SessionAnalyticsService $sessionAnalyticsService,
        private HealthService $healthService,
        private QuotaWindowService $quotaWindowService,
        private CostAnalyticsService $costAnalyticsService,
        private SystemResourceService $systemResourceService,
        private TaskAnalyticsService $taskAnalyticsService,
        private QuotaUtilizationService $quotaUtilizationService,
        private UsageBehaviorService $usageBehaviorService,
    ) {}

    /**
     * Anthropic's server-reported quota — the authoritative percentage.
     *
     * Manual only, on purpose: this hits an undocumented Anthropic endpoint, so
     * it fires when the user clicks, never on a timer.
     */
    public function refreshQuota(): JsonResponse
    {
        $snapshot = $this->quotaUtilizationService->refresh();
        $data = $this->quotaUtilizationService->present($snapshot);

        return response()->json([
            'data' => $data,
            'meta' => ['as_of' => $data['fetched_at']],
        ]);
    }

    /**
     * Calibration pairs: consecutive real readings in one window, next to the
     * local spend recorded at each. The data behind "can we advance the
     * percentage between fetches?" — read-only, and deliberately fits nothing.
     */
    public function quotaCalibration(Request $request): JsonResponse
    {
        $input = $request->validate([
            'max_lag_s' => ['sometimes', 'integer', 'min:0', 'max:3600'],
        ]);

        return response()->json([
            'data' => $this->quotaUtilizationService->calibration($input['max_lag_s'] ?? QuotaUtilizationService::DEFAULT_MAX_LAG_S),
        ]);
    }

    /** The last reading we pulled, with its age. Never triggers a fetch. */
    public function latestQuota(): JsonResponse
    {
        $data = $this->quotaUtilizationService->present($this->quotaUtilizationService->latest());

        return response()->json([
            'data' => $data,
            'meta' => ['as_of' => $data['fetched_at']],
        ]);
    }

    /** Current 5-hour window: consumption, burn, and how it compares to a typical window. */
    public function costWindow(Request $request): JsonResponse
    {
        $input = $request->validate([
            'account' => ['sometimes', Rule::in(SessionAccountService::KEYS)],
        ]);

        $data = $this->costAnalyticsService->window($input['account'] ?? null);

        return response()->json([
            'data' => $data,
            'meta' => ['as_of' => $data['as_of'] ?? null],
        ]);
    }

    /** Per-project token/cost breakdown for a range, ranked by tokens. */
    public function costProjects(Request $request): JsonResponse
    {
        $input = $request->validate([
            'range' => ['sometimes', Rule::in(CostAnalyticsService::RANGES)],
            'group' => ['sometimes', Rule::enum(ProjectGroup::class)],
            'account' => ['sometimes', Rule::in(SessionAccountService::KEYS)],
        ]);

        $group = isset($input['group']) ? ProjectGroup::from($input['group']) : null;
        $account = $input['account'] ?? null;
        $data = $this->costAnalyticsService->projects($input['range'] ?? '7d', $group, $account);

        return response()->json([
            'data' => $data,
            'meta' => ['range' => $data['range'], 'group' => $group?->value, 'account' => $account],
        ]);
    }

    /** Heaviest sessions for one project in a range — the drilldown. */
    public function costProjectSessions(Request $request, string $slug): JsonResponse
    {
        $input = $request->validate([
            'range' => ['sometimes', Rule::in(CostAnalyticsService::RANGES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'account' => ['sometimes', Rule::in(SessionAccountService::KEYS)],
        ]);

        $project = Project::where('slug', $slug)->firstOrFail();

        return response()->json([
            'data' => $this->costAnalyticsService->sessions(
                $project,
                $input['range'] ?? '7d',
                $input['limit'] ?? 5,
                $input['account'] ?? null,
            ),
        ]);
    }

    /** Usage behavior breakdown — cache miss / long context / subagent-heavy / parallel / cron shares. */
    public function usageBehaviors(Request $request): JsonResponse
    {
        $input = $request->validate([
            'range' => ['sometimes', Rule::in(UsageBehaviorService::RANGES)],
            'account' => ['sometimes', Rule::in(SessionAccountService::KEYS)],
        ]);

        $data = $this->usageBehaviorService->behaviors($input['range'] ?? '7d', $input['account'] ?? null);

        return response()->json([
            'data' => $data,
            'meta' => ['range' => $data['range'], 'account' => $data['account']],
        ]);
    }

    /** Backlog pressure, flow, age and deadline coverage for the task-analysis page. */
    public function tasks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group' => ['sometimes', Rule::enum(ProjectGroup::class)],
            'weeks' => ['sometimes', 'integer', 'min:4', 'max:52'],
        ]);

        $group = isset($data['group']) ? ProjectGroup::from($data['group']) : null;
        $weeks = $data['weeks'] ?? 12;

        return response()->json([
            'data' => $this->taskAnalyticsService->summary($group, $weeks),
            'meta' => ['group' => $group?->value, 'weeks' => $weeks],
        ]);
    }

    public function costs(): JsonResponse
    {
        $summary = $this->sessionAnalyticsService->getGlobalCostSummary();

        return response()->json(['data' => $summary]);
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'warnings' => $this->healthService->getHealthWarnings(),
            'insights' => $this->healthService->getWeeklyInsights(),
        ]);
    }

    public function sessions(): JsonResponse
    {
        $breakdown = $this->sessionAnalyticsService->getWeeklyCostBreakdown();

        return response()->json(['data' => $breakdown]);
    }

    public function todaySessions(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? now()->toDateString();

        return response()->json([
            'data' => $this->sessionAnalyticsService->getTodaySessionSpans($date),
            'meta' => ['date' => $date],
        ]);
    }

    public function quotaWindow(): JsonResponse
    {
        return response()->json($this->quotaWindowService->current());
    }

    /** Live machine vitals + hang-risk verdict + per-session memory attribution. */
    public function resources(): JsonResponse
    {
        return response()->json(['data' => $this->systemResourceService->snapshot()]);
    }

    /** Persisted resource trend for the sparkline. */
    public function resourcesTrend(Request $request): JsonResponse
    {
        $data = $request->validate(['window' => ['sometimes', 'integer', 'min:5', 'max:1440']]);
        $window = $data['window'] ?? 120;

        return response()->json([
            'data' => $this->systemResourceService->trend($window),
            'meta' => ['window' => $window],
        ]);
    }
}
