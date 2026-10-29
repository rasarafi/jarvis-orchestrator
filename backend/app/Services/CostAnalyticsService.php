<?php

namespace App\Services;

use App\Enums\ProjectGroup;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Token and cost analytics for the /costs page: one 5-hour window strip, a
 * per-project breakdown for a range, and per-session drilldown.
 *
 * DELIBERATELY ABSENT: any "percent of quota" figure. The 5-hour limit is
 * enforced server-side and is not a linear function of the token counts stored
 * locally — measured against a real reading of 39% used, the implied ceiling
 * came out 12x smaller than the largest block actually observed. The honest
 * local comparison is this window against the user's own median window, which
 * is what `window()` returns. The authoritative number is FETCHED, not derived —
 * see QuotaUtilizationService — and `calibrationSample()` below exists to pair
 * those real readings with local spend so the relationship can be studied.
 */
class CostAnalyticsService
{
    /**
     * A session counts as active if it produced a message this recently — measured
     * from the most recent INDEXED activity, not wall-clock now. The index lags the
     * scan schedule, so anchoring to now() makes everything read as idle whenever a
     * scan is overdue. Staleness is surfaced through `as_of` instead.
     */
    private const ACTIVE_WITHIN_MINUTES = 15;

    /** How many subagent rows a session's drilldown carries. */
    private const SUBAGENTS_PER_SESSION = 8;

    /** Ranges the page offers, mapped to their lookback. `live` = current block. */
    public const RANGES = ['live', '24h', '7d', '30d'];

    public function __construct(
        private QuotaWindowService $quotaWindow,
        private SessionAccountService $accounts,
    ) {}

    // =========================================================
    // Window strip
    // =========================================================

    /**
     * The current 5-hour window: consumption, burn, projection, and how it
     * compares to a typical window.
     */
    public function window(?string $account = null): array
    {
        $blocks = $this->quotaWindow->blocks(account: $account);
        $current = $this->quotaWindow->currentBlock($account);
        $blockHours = $this->quotaWindow->blockHours();

        // The open block must not contribute to the median it is measured
        // against, or a heavy window drags its own baseline up.
        $closed = $current !== null ? array_slice($blocks, 0, -1) : $blocks;
        $median = $this->median(array_map(fn ($b) => (int) $b['tokens'], $closed));

        if ($current === null) {
            return [
                'open' => false,
                'median_tokens' => $median,
                'account' => $account,
                'accounts' => $this->accounts->present(),
                'as_of' => $this->lastActivity()?->toIso8601String(),
                'provenance' => 'local_estimate',
            ];
        }

        $startedAt = $current['started_at'];
        $endsAt = $startedAt->copy()->addHours($blockHours);
        $tokens = (int) $current['tokens'];

        $elapsedMinutes = max(1, (int) round($startedAt->diffInMinutes(now())));
        $blockMinutes = $blockHours * 60;
        $burn = $this->burnPerMinute($account);
        $projected = $tokens + (int) round($burn * max(0, $blockMinutes - $elapsedMinutes));

        return [
            'open' => true,
            'started_at' => $startedAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            // 'server_anchor' = boundary taken from Anthropic's real reset time;
            // 'hour_floor' = the local guess, which runs ~10 min early.
            'boundary_source' => $current['source'] ?? 'hour_floor',
            'elapsed_pct' => (int) min(100, round($elapsedMinutes / $blockMinutes * 100)),
            'resets_in_minutes' => max(0, (int) round(now()->diffInMinutes($endsAt, false))),
            'tokens' => $tokens,
            'cost_usd' => $this->costBetween($startedAt, now(), $account),
            'burn_tokens_per_min' => (int) round($burn),
            'projected_tokens' => $projected,
            'median_tokens' => $median,
            // The headline comparison. Null rather than a divide-by-zero lie.
            'vs_median' => $median > 0 ? round($tokens / $median, 2) : null,
            'projected_vs_median' => $median > 0 ? round($projected / $median, 2) : null,
            'active_sessions' => $this->activeSessionCount($account),
            'model_mix' => $this->modelMix($startedAt, null, $account),
            'heaviest_project' => $this->heaviestProject($startedAt, $tokens, $account),
            'account' => $account,
            'accounts' => $this->accounts->present(),
            'as_of' => $this->lastActivity()?->toIso8601String(),
            'provenance' => 'local_estimate',
        ];
    }

    /**
     * Everything needed to pair a real quota reading with local spend.
     *
     * Captured at fetch time and frozen into the snapshot row, so each row is a
     * self-contained calibration sample: what Anthropic said, what we had
     * counted locally at that instant, and how stale that count was.
     *
     * Why raw token classes per model rather than a single cost number: the
     * quota's weighting is unknown, and raw tokens are a terrible proxy for it
     * (95-98% of them are cache reads, while the output share swings ~3x day to
     * day). Storing the unweighted components lets any weighting be fitted
     * later, against data we cannot go back and re-collect.
     *
     * @return array<string,mixed>
     */
    public function calibrationSample(?string $account = null): array
    {
        $current = $this->quotaWindow->currentBlock($account);
        $startedAt = $current['started_at'] ?? null;
        $indexedThrough = $this->lastActivity();

        return [
            'open' => $current !== null,
            'window_started_at' => $startedAt?->toIso8601String(),
            'tokens' => $current !== null ? (int) $current['tokens'] : 0,
            'cost_usd' => $startedAt !== null ? $this->costBetween($startedAt, now(), $account) : 0.0,
            // The index lags the scan schedule, so a sample is only as current
            // as this. Without it a pair looks precise when it is not.
            'indexed_through' => $indexedThrough?->toIso8601String(),
            'scan_lag_s' => $indexedThrough !== null
                ? (int) max(0, round(now()->diffInSeconds($indexedThrough, true)))
                : null,
            'models' => $startedAt !== null ? $this->tokenClassesByModel($startedAt, $account) : [],
            'account' => $account,
        ];
    }

    /**
     * Per-model token totals split by class, for the window.
     *
     * @return list<array<string,mixed>>
     */
    private function tokenClassesByModel(Carbon $since, ?string $account = null): array
    {
        $query = DB::table('sessions.messages')
            ->where('timestamp', '>=', $since)
            ->whereNotNull('model')
            ->where('model', '!=', '<synthetic>');

        $this->accounts->constrainMessages($query, $account, 'sessions.messages.session_id');

        return $query
            ->groupBy('model')
            ->selectRaw('
                model,
                coalesce(sum(input_tokens), 0) as input,
                coalesce(sum(output_tokens), 0) as output,
                coalesce(sum(cache_creation_tokens), 0) as cache_creation,
                coalesce(sum(cache_creation_1h_tokens), 0) as cache_creation_1h,
                coalesce(sum(cache_read_tokens), 0) as cache_read
            ')
            ->get()
            ->map(fn ($r) => [
                'model' => $r->model,
                'input' => (int) $r->input,
                'output' => (int) $r->output,
                'cache_creation' => (int) $r->cache_creation,
                'cache_creation_1h' => (int) $r->cache_creation_1h,
                'cache_read' => (int) $r->cache_read,
            ])
            ->all();
    }

    // =========================================================
    // Per-project breakdown
    // =========================================================

    /**
     * Every project with activity in the range, ranked by tokens, with the
     * previous equal-length period alongside for the trend.
     *
     * @return array{range: string, since: string, total_tokens: int, total_cost_usd: float, projects: array<int, array<string, mixed>>}
     */
    public function projects(string $range, ?ProjectGroup $group = null, ?string $account = null): array
    {
        [$since, $until, $prevSince] = $this->rangeBounds($range);

        $map = $this->ccProjectMap();
        $current = $this->tokensByCcProject($since, $until, $account);
        $previous = $this->tokensByCcProject($prevSince, $since, $account);
        $activeIds = $this->activeCcProjectIds($account);
        $modelsByCc = $this->modelsByCcProject($since, $until, $account);

        $rows = [];
        foreach ($current as $ccId => $agg) {
            $project = $map[$ccId] ?? null;
            if ($project === null) {
                continue; // A CC project outside every onboarded project root.
            }
            if ($group !== null && $project->group_key?->value !== $group->value) {
                continue;
            }

            $slug = $project->slug;
            $rows[$slug] ??= [
                'slug' => $slug,
                'name' => $project->name,
                'group_key' => $project->group_key?->value,
                'tokens' => 0,
                'cost_usd' => 0.0,
                'sessions' => 0,
                'previous_tokens' => 0,
                'last_activity_at' => null,
                'live' => false,
                // Model tokens accumulate across every CC project dir this
                // Jarvis project owns, then get turned into shares below.
                'model_tokens' => [],
            ];

            $rows[$slug]['tokens'] += $agg['tokens'];
            $rows[$slug]['cost_usd'] += $agg['cost'];
            $rows[$slug]['sessions'] += $agg['sessions'];
            $rows[$slug]['live'] = $rows[$slug]['live'] || in_array($ccId, $activeIds, true);

            foreach ($modelsByCc[$ccId] ?? [] as $model => $modelTokens) {
                $rows[$slug]['model_tokens'][$model] = ($rows[$slug]['model_tokens'][$model] ?? 0) + $modelTokens;
            }

            $last = $agg['last_activity'];
            if ($last !== null && ($rows[$slug]['last_activity_at'] === null || $last > $rows[$slug]['last_activity_at'])) {
                $rows[$slug]['last_activity_at'] = $last;
            }
        }

        // Fold the previous period in through the same project mapping.
        foreach ($previous as $ccId => $agg) {
            $project = $map[$ccId] ?? null;
            if ($project === null || !isset($rows[$project->slug])) {
                continue;
            }
            $rows[$project->slug]['previous_tokens'] += $agg['tokens'];
        }

        $totalTokens = array_sum(array_column($rows, 'tokens'));

        $projects = array_values($rows);
        foreach ($projects as &$row) {
            $row['cost_usd'] = round($row['cost_usd'], 2);
            $row['share_pct'] = $totalTokens > 0 ? round($row['tokens'] / $totalTokens * 100, 1) : 0.0;
            // A project with no prior activity is "new", not an infinite rise.
            $row['trend_pct'] = $row['previous_tokens'] > 0
                ? (int) round(($row['tokens'] - $row['previous_tokens']) / $row['previous_tokens'] * 100)
                : null;
            $row['last_activity_at'] = $row['last_activity_at']
                ? Carbon::parse($row['last_activity_at'])->toIso8601String()
                : null;
            $row['models'] = $this->modelShares($row['model_tokens']);
            unset($row['model_tokens']);
        }
        unset($row);

        usort($projects, fn ($a, $b) => $b['tokens'] <=> $a['tokens']);

        return [
            'range' => $range,
            'since' => $since->toIso8601String(),
            'account' => $account,
            'models' => $this->modelMix($since, $until, $account),
            'total_tokens' => $totalTokens,
            'total_cost_usd' => round(array_sum(array_column($projects, 'cost_usd')), 2),
            'projects' => $projects,
        ];
    }

    // =========================================================
    // Per-session drilldown
    // =========================================================

    /**
     * The heaviest sessions for one project in the range.
     *
     * @return array{slug: string, range: string, total_sessions: int, sessions: array<int, array<string, mixed>>}
     */
    public function sessions(Project $project, string $range, int $limit = 10, ?string $account = null): array
    {
        [$since, $until] = $this->rangeBounds($range);

        $ccIds = $this->ccProjectIdsFor($project);
        if ($ccIds === []) {
            return ['slug' => $project->slug, 'range' => $range, 'account' => $account, 'total_sessions' => 0, 'sessions' => []];
        }

        // Subagent spend belongs to the session that spawned it. Rolling it up
        // in SQL (not after the fact in PHP) is what makes the ordering honest:
        // rank by own + subagent tokens, then take the top N.
        $subagents = DB::table('sessions.sessions')
            ->where('kind', 'subagent')
            ->where('last_activity_timestamp', '>=', $since)
            ->where('last_activity_timestamp', '<', $until)
            ->groupBy('parent_session_id')
            ->selectRaw("
                parent_session_id,
                count(*) as agents,
                coalesce(sum(estimated_cost_usd), 0) as cost,
                coalesce(sum(total_input_tokens), 0)
                    + coalesce(sum(total_output_tokens), 0)
                    + coalesce(sum(total_cache_creation_tokens), 0)
                    + coalesce(sum(total_cache_read_tokens), 0) as tokens
            ");

        $base = DB::table('sessions.sessions as s')
            ->whereIn('s.project_id', $ccIds)
            ->where('s.kind', 'main')
            ->where('s.last_activity_timestamp', '>=', $since)
            ->where('s.last_activity_timestamp', '<', $until);

        $this->accounts->constrainSessions($base, $account, 's');

        $total = (clone $base)->count();

        $rows = (clone $base)
            ->leftJoinSub($subagents, 'a', 'a.parent_session_id', '=', 's.session_id')
            ->selectRaw("
                s.session_id, s.first_prompt, s.git_branch, s.message_count,
                s.last_activity_timestamp, s.account,
                s.estimated_cost_usd + coalesce(a.cost, 0) as cost,
                coalesce(a.agents, 0) as agents,
                coalesce(a.tokens, 0) as subagent_tokens,
                coalesce(s.total_input_tokens, 0)
                    + coalesce(s.total_output_tokens, 0)
                    + coalesce(s.total_cache_creation_tokens, 0)
                    + coalesce(s.total_cache_read_tokens, 0) as own_tokens,
                coalesce(s.total_input_tokens, 0)
                    + coalesce(s.total_output_tokens, 0)
                    + coalesce(s.total_cache_creation_tokens, 0)
                    + coalesce(s.total_cache_read_tokens, 0)
                    + coalesce(a.tokens, 0) as tokens
            ")
            ->orderByDesc('tokens')
            ->limit($limit)
            ->get();

        $activeSince = $this->activeSince();
        $ids = $rows->pluck('session_id')->all();
        $models = $this->modelsBySession($ids, true);
        $children = $this->subagentRows($ids, $since, $until);

        return [
            'slug' => $project->slug,
            'range' => $range,
            'account' => $account,
            'total_sessions' => $total,
            'sessions' => $rows->map(fn ($r) => [
                'session_id' => $r->session_id,
                'first_prompt' => $this->condense($r->first_prompt),
                'git_branch' => $r->git_branch,
                'account' => $r->account,
                'messages' => (int) $r->message_count,
                'tokens' => (int) $r->tokens,
                'own_tokens' => (int) $r->own_tokens,
                'subagent_tokens' => (int) $r->subagent_tokens,
                'subagent_count' => (int) $r->agents,
                'cost_usd' => round((float) $r->cost, 2),
                'models' => $this->modelShares($models[$r->session_id] ?? []),
                'subagents' => $children[$r->session_id] ?? [],
                'last_activity_at' => $r->last_activity_timestamp
                    ? Carbon::parse($r->last_activity_timestamp)->toIso8601String()
                    : null,
                'live' => $r->last_activity_timestamp !== null
                    && Carbon::parse($r->last_activity_timestamp)->gte($activeSince),
            ])->all(),
        ];
    }

    // =========================================================
    // Internals
    // =========================================================

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Carbon} since, until, previous-period start
     */
    private function rangeBounds(string $range): array
    {
        $until = now();

        $since = match ($range) {
            'live' => $this->quotaWindow->currentBlock()['started_at']
                ?? now()->subHours($this->quotaWindow->blockHours()),
            '24h' => now()->subDay(),
            '30d' => now()->subDays(30),
            default => now()->subDays(7),
        };

        $since = $since->copy();
        // The comparison period is the same length immediately before it.
        $prevSince = $since->copy()->subMinutes(max(1, (int) round($since->diffInMinutes($until))));

        return [$since, $until, $prevSince];
    }

    /**
     * Token/cost/session aggregates per CC project between two instants.
     *
     * @return array<int, array{tokens: int, cost: float, sessions: int, last_activity: string|null}>
     */
    private function tokensByCcProject(Carbon $since, Carbon $until, ?string $account = null): array
    {
        $query = DB::table('sessions.sessions')
            ->where('last_activity_timestamp', '>=', $since)
            ->where('last_activity_timestamp', '<', $until);

        $this->accounts->constrainSessions($query, $account);

        $rows = $query
            ->groupBy('project_id')
            ->selectRaw("
                project_id,
                -- Subagent rows carry real spend but are not sessions the user
                -- started, so they count toward tokens and never toward the
                -- session count.
                count(*) filter (where kind = 'main') as sessions,
                coalesce(sum(estimated_cost_usd), 0) as cost,
                max(last_activity_timestamp) as last_activity,
                coalesce(sum(total_input_tokens), 0)
                    + coalesce(sum(total_output_tokens), 0)
                    + coalesce(sum(total_cache_creation_tokens), 0)
                    + coalesce(sum(total_cache_read_tokens), 0) as tokens
            ")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->project_id] = [
                'tokens' => (int) $r->tokens,
                'cost' => (float) $r->cost,
                'sessions' => (int) $r->sessions,
                'last_activity' => $r->last_activity,
            ];
        }

        return $out;
    }

    /**
     * CC project id => owning JARVIS project, using the longest-prefix SSOT.
     * A naive `LIKE local_path || '%'` counts nested roots twice (portfolio-app
     * lands under portfolio as well as itself).
     *
     * @return array<int, Project>
     */
    private function ccProjectMap(): array
    {
        $projects = Project::query()->whereNotNull('local_path')->get();

        $map = [];
        foreach (DB::table('sessions.projects')->select('id', 'decoded_path')->get() as $cc) {
            $owner = Project::resolveFromCollection($projects, $cc->decoded_path);
            if ($owner !== null) {
                $map[(int) $cc->id] = $owner;
            }
        }

        return $map;
    }

    /** @return array<int, int> CC project ids owned by this project. */
    private function ccProjectIdsFor(Project $project): array
    {
        $ids = [];
        foreach ($this->ccProjectMap() as $ccId => $owner) {
            if ($owner->id === $project->id) {
                $ids[] = $ccId;
            }
        }

        return $ids;
    }

    /** @return array<int, int> CC project ids with a message in the active window. */
    private function activeCcProjectIds(?string $account = null): array
    {
        $query = DB::table('sessions.sessions')
            ->where('last_activity_timestamp', '>=', $this->activeSince());

        $this->accounts->constrainSessions($query, $account);

        return $query
            ->distinct()
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** The instant after which a session is considered still running. */
    private function activeSince(): Carbon
    {
        return ($this->lastActivity() ?? now())->copy()->subMinutes(self::ACTIVE_WITHIN_MINUTES);
    }

    private function activeSessionCount(?string $account = null): int
    {
        $query = DB::table('sessions.sessions')
            ->where('kind', 'main')
            ->where('last_activity_timestamp', '>=', $this->activeSince());

        $this->accounts->constrainSessions($query, $account);

        return $query->count();
    }

    /** Share of tokens by model over a period, heaviest first. */
    private function modelMix(Carbon $since, ?Carbon $until = null, ?string $account = null): array
    {
        $query = DB::table('sessions.messages')
            ->where('timestamp', '>=', $since)
            ->when($until !== null, fn ($q) => $q->where('timestamp', '<', $until))
            ->whereNotNull('model')
            ->where('model', '!=', '<synthetic>');

        $this->accounts->constrainMessages($query, $account, 'sessions.messages.session_id');

        $rows = $query
            ->groupBy('model')
            ->selectRaw("
                model,
                coalesce(sum(input_tokens), 0) + coalesce(sum(output_tokens), 0)
                    + coalesce(sum(cache_creation_tokens), 0)
                    + coalesce(sum(cache_read_tokens), 0) as tokens
            ")
            ->orderByDesc('tokens')
            ->get();

        $total = $rows->sum('tokens');
        if ($total <= 0) {
            return [];
        }

        return $rows->map(fn ($r) => [
            'model' => $r->model,
            'tokens' => (int) $r->tokens,
            'pct' => (int) round($r->tokens / $total * 100),
        ])->all();
    }

    /** The project consuming most of the open window. */
    private function heaviestProject(Carbon $since, int $windowTokens, ?string $account = null): ?array
    {
        $map = $this->ccProjectMap();
        $totals = [];

        foreach ($this->tokensByCcProject($since, now(), $account) as $ccId => $agg) {
            $project = $map[$ccId] ?? null;
            if ($project === null) {
                continue;
            }
            $totals[$project->slug] = ($totals[$project->slug] ?? 0) + $agg['tokens'];
        }

        if ($totals === []) {
            return null;
        }

        arsort($totals);
        $slug = array_key_first($totals);

        return [
            'slug' => $slug,
            'tokens' => $totals[$slug],
            'pct' => $windowTokens > 0 ? (int) round($totals[$slug] / $windowTokens * 100) : null,
        ];
    }

    private function costBetween(Carbon $since, Carbon $until, ?string $account = null): float
    {
        // Session cost is stored per session, not per message, so window cost is
        // apportioned by the share of that session's tokens inside the window.
        $query = DB::table('sessions.messages as m')
            ->join('sessions.sessions as s', 's.session_id', '=', 'm.session_id')
            ->where('m.timestamp', '>=', $since)
            ->where('m.timestamp', '<', $until);

        $this->accounts->constrainSessions($query, $account, 's');

        $cost = $query
            ->whereRaw('(coalesce(s.total_input_tokens,0) + coalesce(s.total_output_tokens,0)
                + coalesce(s.total_cache_creation_tokens,0) + coalesce(s.total_cache_read_tokens,0)) > 0')
            ->selectRaw("
                coalesce(sum(
                    s.estimated_cost_usd
                    * (coalesce(m.input_tokens,0) + coalesce(m.output_tokens,0)
                       + coalesce(m.cache_creation_tokens,0) + coalesce(m.cache_read_tokens,0))::numeric
                    / (coalesce(s.total_input_tokens,0) + coalesce(s.total_output_tokens,0)
                       + coalesce(s.total_cache_creation_tokens,0) + coalesce(s.total_cache_read_tokens,0))::numeric
                ), 0) as cost
            ")
            ->value('cost');

        return round((float) $cost, 2);
    }

    private function burnPerMinute(?string $account = null): float
    {
        $query = DB::table('sessions.messages')
            ->where('timestamp', '>=', now()->subHour());

        $this->accounts->constrainMessages($query, $account, 'sessions.messages.session_id');

        $tokens = (int) $query
            ->selectRaw("
                coalesce(sum(input_tokens), 0) + coalesce(sum(output_tokens), 0)
                    + coalesce(sum(cache_creation_tokens), 0)
                    + coalesce(sum(cache_read_tokens), 0) as tokens
            ")
            ->value('tokens');

        return $tokens / 60;
    }

    private function lastActivity(): ?Carbon
    {
        $ts = DB::table('sessions.messages')->max('timestamp');

        return $ts ? Carbon::parse($ts) : null;
    }

    /** @param array<int, int> $values */
    private function median(array $values): int
    {
        $values = array_values(array_filter($values, fn ($v) => $v > 0));
        if ($values === []) {
            return 0;
        }

        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? (int) round(($values[$mid - 1] + $values[$mid]) / 2)
            : $values[$mid];
    }

    // =========================================================
    // Model breakdown
    // =========================================================

    /**
     * Tokens by model, per CC project, over a period.
     *
     * @return array<int, array<string, int>> cc project id => model => tokens
     */
    private function modelsByCcProject(Carbon $since, Carbon $until, ?string $account = null): array
    {
        $query = DB::table('sessions.messages as m')
            ->join('sessions.sessions as s', 's.session_id', '=', 'm.session_id')
            ->where('m.timestamp', '>=', $since)
            ->where('m.timestamp', '<', $until)
            ->whereNotNull('m.model')
            ->where('m.model', '!=', '<synthetic>');

        $this->accounts->constrainSessions($query, $account, 's');

        $rows = $query
            ->groupBy('s.project_id', 'm.model')
            ->selectRaw("
                s.project_id, m.model,
                coalesce(sum(m.input_tokens), 0) + coalesce(sum(m.output_tokens), 0)
                    + coalesce(sum(m.cache_creation_tokens), 0)
                    + coalesce(sum(m.cache_read_tokens), 0) as tokens
            ")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->project_id][(string) $r->model] = (int) $r->tokens;
        }

        return $out;
    }

    /**
     * Tokens by model for specific sessions.
     *
     * @param  array<int, string>  $sessionIds
     * @param  bool  $includeSubagents  fold each session's subagent transcripts
     *                                  into its own totals — the drilldown shows
     *                                  a session's full spend, agents included
     * @return array<string, array<string, int>> session id => model => tokens
     */
    private function modelsBySession(array $sessionIds, bool $includeSubagents = false): array
    {
        if ($sessionIds === []) {
            return [];
        }

        $query = DB::table('sessions.messages as m')
            ->join('sessions.sessions as s', 's.session_id', '=', 'm.session_id')
            ->whereNotNull('m.model')
            ->where('m.model', '!=', '<synthetic>');

        $includeSubagents
            ? $query->where(fn ($q) => $q->whereIn('s.session_id', $sessionIds)
                ->orWhereIn('s.parent_session_id', $sessionIds))
            : $query->whereIn('s.session_id', $sessionIds);

        $rows = $query
            ->groupByRaw('coalesce(s.parent_session_id, s.session_id), m.model')
            ->selectRaw("
                coalesce(s.parent_session_id, s.session_id) as owner, m.model,
                coalesce(sum(m.input_tokens), 0) + coalesce(sum(m.output_tokens), 0)
                    + coalesce(sum(m.cache_creation_tokens), 0)
                    + coalesce(sum(m.cache_read_tokens), 0) as tokens
            ")
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->owner][(string) $r->model] = (int) $r->tokens;
        }

        return $out;
    }

    /**
     * Turn raw model=>tokens into shares, heaviest first. Zero-token models are
     * dropped rather than rendered as a 0% sliver.
     *
     * @param  array<string, int>  $modelTokens
     * @return array<int, array{model: string, tokens: int, pct: int}>
     */
    private function modelShares(array $modelTokens): array
    {
        $total = array_sum($modelTokens);
        if ($total <= 0) {
            return [];
        }

        arsort($modelTokens);

        $out = [];
        foreach ($modelTokens as $model => $tokens) {
            if ($tokens <= 0) {
                continue;
            }
            $out[] = [
                'model' => $model,
                'tokens' => $tokens,
                'pct' => (int) round($tokens / $total * 100),
            ];
        }

        return $out;
    }

    /**
     * The subagent transcripts belonging to given sessions, heaviest first.
     *
     * @param  array<int, string>  $parentIds
     * @return array<string, array<int, array<string, mixed>>> parent id => rows
     */
    private function subagentRows(array $parentIds, Carbon $since, Carbon $until): array
    {
        if ($parentIds === []) {
            return [];
        }

        $rows = DB::table('sessions.sessions')
            ->where('kind', 'subagent')
            ->whereIn('parent_session_id', $parentIds)
            ->where('last_activity_timestamp', '>=', $since)
            ->where('last_activity_timestamp', '<', $until)
            ->selectRaw("
                session_id, parent_session_id, first_prompt, message_count,
                last_activity_timestamp, estimated_cost_usd,
                coalesce(total_input_tokens, 0)
                    + coalesce(total_output_tokens, 0)
                    + coalesce(total_cache_creation_tokens, 0)
                    + coalesce(total_cache_read_tokens, 0) as tokens
            ")
            ->orderByDesc('tokens')
            ->get();

        // Heaviest few only — a single session can spawn 30+ agents, and the row
        // reports the full count separately. Capped rather than paginated: the
        // drilldown is for spotting where the tokens went, not an agent index.
        $out = [];
        foreach ($rows as $r) {
            $parent = (string) $r->parent_session_id;
            if (count($out[$parent] ?? []) >= self::SUBAGENTS_PER_SESSION) {
                continue;
            }
            $out[$parent][] = [
                'session_id' => $r->session_id,
                'first_prompt' => $this->condense($r->first_prompt),
                'messages' => (int) $r->message_count,
                'tokens' => (int) $r->tokens,
                'cost_usd' => round((float) $r->estimated_cost_usd, 2),
                'last_activity_at' => $r->last_activity_timestamp
                    ? Carbon::parse($r->last_activity_timestamp)->toIso8601String()
                    : null,
            ];
        }

        return $out;
    }

    /** Collapse the whitespace that makes multi-line prompts unreadable in a table. */
    private function condense(?string $prompt): string
    {
        if ($prompt === null || trim($prompt) === '') {
            return '(no prompt)';
        }

        return trim(preg_replace('/\s+/u', ' ', $prompt));
    }
}
