<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Part A3 — the "what's
 * contributing to your limits usage" breakdown the CLI's own `/usage` shows,
 * built from the FULL indexed corpus rather than one machine's local sessions.
 *
 * Every threshold/definition below is quoted from the spec; do not drift them
 * without updating both places.
 */
class UsageBehaviorService
{
    /** cache_miss: % of tokens in messages where cache_creation_tokens exceeds this. */
    public const CACHE_MISS_THRESHOLD = 100_000;

    /** long_context: % of tokens in messages where input+cache_read+cache_creation exceeds this. */
    public const LONG_CONTEXT_THRESHOLD = 150_000;

    /** high_parallel: a minute counts as parallel once this many sessions were concurrently active. */
    public const HIGH_PARALLEL_MIN_SESSIONS = 4;

    /** No skill was active for this stretch of the session. */
    private const NO_SKILL_LABEL = '__none__';

    /** No subagent_type sidecar (26% of Agent calls omit it — a real category, not missing data). */
    private const UNSPECIFIED_SUBAGENT_TYPE = 'general-purpose (unspecified)';

    public const RANGES = ['24h', '7d'];

    public function __construct(private SessionAccountService $accounts) {}

    /** @return array<string, mixed> */
    public function behaviors(string $range, ?string $account = null): array
    {
        [$since, $until] = $this->rangeBounds($range);

        $skills = $this->skillShares($since, $until, $account);

        return [
            'range' => $range,
            'since' => $since->toIso8601String(),
            'until' => $until->toIso8601String(),
            'account' => $account,
            'cache_miss' => $this->tokenShareByCondition(
                $since,
                $until,
                $account,
                'cache_creation_tokens > ' . self::CACHE_MISS_THRESHOLD,
            ),
            'long_context' => $this->tokenShareByCondition(
                $since,
                $until,
                $account,
                '(coalesce(input_tokens,0) + coalesce(cache_read_tokens,0) + coalesce(cache_creation_tokens,0)) > ' . self::LONG_CONTEXT_THRESHOLD,
            ),
            'subagent_heavy' => $this->subagentHeavy($since, $until, $account),
            'high_parallel' => $this->highParallel($since, $until, $account),
            'cron' => $this->cron($since, $until, $account),
            'subagent_types' => $this->subagentTypeShares($since, $until, $account),
            'skills' => $skills,
            'plugin' => $this->pluginShare($skills),
            'mcp_servers' => $this->mcpServerShares($since, $until, $account),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rangeBounds(string $range): array
    {
        $until = now();
        $since = $range === '24h' ? now()->subDay() : now()->subDays(7);

        return [$since, $until];
    }

    private function tokenSql(string $alias = ''): string
    {
        $p = $alias !== '' ? $alias . '.' : '';

        return "coalesce({$p}input_tokens,0) + coalesce({$p}output_tokens,0)"
            . " + coalesce({$p}cache_creation_tokens,0) + coalesce({$p}cache_read_tokens,0)";
    }

    /**
     * cache_miss / long_context: both are "% of tokens in messages matching a
     * per-message condition", so they share one implementation.
     *
     * @return array{tokens: int, total_tokens: int, pct: int}
     */
    private function tokenShareByCondition(Carbon $since, Carbon $until, ?string $account, string $condition): array
    {
        $query = DB::table('sessions.messages')
            ->where('timestamp', '>=', $since)
            ->where('timestamp', '<', $until);

        $this->accounts->constrainMessages($query, $account, 'sessions.messages.session_id');

        $tokenExpr = $this->tokenSql();
        $row = $query->selectRaw("
            coalesce(sum({$tokenExpr}), 0) as total,
            coalesce(sum({$tokenExpr}) filter (where {$condition}), 0) as matched
        ")->first();

        $total = (int) $row->total;
        $matched = (int) $row->matched;

        return [
            'tokens' => $matched,
            'total_tokens' => $total,
            'pct' => $total > 0 ? (int) round($matched / $total * 100) : 0,
        ];
    }

    /**
     * "% of tokens in sessions where the sum over subagent children exceeds
     * the parent's own tokens." A session's tokens are own + subagents (same
     * convention as CostAnalyticsService's drilldown), so a heavy session's
     * full total — not just its own share — counts toward the numerator.
     *
     * @return array{tokens: int, total_tokens: int, pct: int}
     */
    private function subagentHeavy(Carbon $since, Carbon $until, ?string $account): array
    {
        $subTotals = DB::table('sessions.sessions')
            ->where('kind', 'subagent')
            ->groupBy('parent_session_id')
            ->selectRaw('parent_session_id, ' . $this->childTokenSum() . ' as sub_tokens');

        $base = DB::table('sessions.sessions as s')
            ->where('s.kind', 'main')
            ->where('s.last_activity_timestamp', '>=', $since)
            ->where('s.last_activity_timestamp', '<', $until);

        $this->accounts->constrainSessions($base, $account, 's');

        $rows = $base
            ->leftJoinSub($subTotals, 'a', 'a.parent_session_id', '=', 's.session_id')
            ->selectRaw('
                (' . $this->sessionTokenSql('s') . ') as own_tokens,
                coalesce(a.sub_tokens, 0) as sub_tokens
            ')
            ->get();

        $total = 0;
        $heavy = 0;
        foreach ($rows as $r) {
            $sessionTotal = (int) $r->own_tokens + (int) $r->sub_tokens;
            $total += $sessionTotal;
            if ((int) $r->sub_tokens > (int) $r->own_tokens) {
                $heavy += $sessionTotal;
            }
        }

        return [
            'tokens' => $heavy,
            'total_tokens' => $total,
            'pct' => $total > 0 ? (int) round($heavy / $total * 100) : 0,
        ];
    }

    private function childTokenSum(): string
    {
        return 'coalesce(sum(total_input_tokens),0) + coalesce(sum(total_output_tokens),0)'
            . ' + coalesce(sum(total_cache_creation_tokens),0) + coalesce(sum(total_cache_read_tokens),0)';
    }

    /** Token expression for `sessions.sessions`' own (non-message) total_* columns. */
    private function sessionTokenSql(string $alias): string
    {
        return "coalesce({$alias}.total_input_tokens,0) + coalesce({$alias}.total_output_tokens,0)"
            . " + coalesce({$alias}.total_cache_creation_tokens,0) + coalesce({$alias}.total_cache_read_tokens,0)";
    }

    /**
     * "% of tokens during timestamps where 4+ sessions were concurrently
     * active" — read off the precomputed `sessions.minute_concurrency` table
     * rather than an interval-overlap join.
     *
     * @return array{tokens: int, total_tokens: int, pct: int}
     */
    private function highParallel(Carbon $since, Carbon $until, ?string $account): array
    {
        $query = DB::table('sessions.messages as m')
            ->join('sessions.minute_concurrency as c', function ($join) {
                $join->on(DB::raw("date_trunc('minute', m.timestamp)"), '=', 'c.minute_bucket');
            })
            ->where('m.timestamp', '>=', $since)
            ->where('m.timestamp', '<', $until);

        $this->accounts->constrainMessages($query, $account, 'm.session_id');

        $tokenExpr = $this->tokenSql('m');
        $row = $query->selectRaw("
            coalesce(sum({$tokenExpr}), 0) as total,
            coalesce(sum({$tokenExpr}) filter (where c.active_sessions >= " . self::HIGH_PARALLEL_MIN_SESSIONS . '), 0) as matched
        ')->first();

        $total = (int) $row->total;
        $matched = (int) $row->matched;

        return [
            'tokens' => $matched,
            'total_tokens' => $total,
            'pct' => $total > 0 ? (int) round($matched / $total * 100) : 0,
        ];
    }

    /**
     * "No human-authored user message in the session." Operationalized via
     * `entrypoint` ('cli' = interactive; 'sdk-cli' | 'sdk-py' = a headless
     * invocation) — verified against real ClaudeCliService (`claude -p`)
     * transcripts, cleanly separating them from interactive sessions. Sessions
     * with no captured entrypoint yet (pre-backfill) are excluded from both
     * sides rather than guessed at; `available: false` if that leaves nothing.
     *
     * @return array{available: bool, tokens: int, total_tokens: int, pct: int, reason?: string}
     */
    private function cron(Carbon $since, Carbon $until, ?string $account): array
    {
        $query = DB::table('sessions.sessions as s')
            ->where('s.kind', 'main')
            ->where('s.last_activity_timestamp', '>=', $since)
            ->where('s.last_activity_timestamp', '<', $until)
            ->whereNotNull('s.entrypoint');

        $this->accounts->constrainSessions($query, $account, 's');

        $tokenExpr = $this->sessionTokenSql('s');

        $row = $query->selectRaw("
            coalesce(sum({$tokenExpr}), 0) as total,
            coalesce(sum({$tokenExpr}) filter (where s.entrypoint != 'cli'), 0) as matched
        ")->first();

        $total = (int) $row->total;

        if ($total === 0) {
            return [
                'available' => false,
                'tokens' => 0,
                'total_tokens' => 0,
                'pct' => 0,
                'reason' => 'No sessions in range carry entrypoint data yet — run jarvis:backfill-subagent-attribution.',
            ];
        }

        $matched = (int) $row->matched;

        return [
            'available' => true,
            'tokens' => $matched,
            'total_tokens' => $total,
            'pct' => (int) round($matched / $total * 100),
        ];
    }

    /**
     * Subagent share by `subagent_type` (A1). Null renders as an explicit
     * bucket, not dropped — 26% of `Agent` calls omit `subagent_type` and fall
     * back to general-purpose, so null is real data.
     *
     * @return array{items: list<array{label: string, tokens: int, pct: int}>, total_tokens: int}
     */
    private function subagentTypeShares(Carbon $since, Carbon $until, ?string $account): array
    {
        $tokenExpr = $this->sessionTokenSql('sessions.sessions');

        $query = DB::table('sessions.sessions')
            ->where('kind', 'subagent')
            ->where('last_activity_timestamp', '>=', $since)
            ->where('last_activity_timestamp', '<', $until);

        $this->accounts->constrainSessions($query, $account);

        $rows = $query
            ->selectRaw("coalesce(subagent_type, ?) as label, sum({$tokenExpr}) as tokens", [self::UNSPECIFIED_SUBAGENT_TYPE])
            ->groupBy('label')
            ->get();

        return $this->tokenShares($rows);
    }

    /**
     * Skill share (A2) — Option A propagation: from the invoking message to
     * end of session or the next `Skill` invocation, whichever comes first.
     * The invoking message's own (small) tokens are NOT included — the cost
     * being measured is the skill's expansion sitting in context for
     * subsequent turns, not the cost of the tool_use call itself.
     *
     * Scoped to `kind = 'main'` sessions: a subagent invoking a skill is not
     * covered by this pass (out of scope for this build).
     *
     * @return array{items: list<array{label: string, tokens: int, pct: int, is_plugin: bool}>, total_tokens: int}
     */
    private function skillShares(Carbon $since, Carbon $until, ?string $account): array
    {
        $sessionIds = $this->mainSessionIdsInRange($since, $until, $account);
        if ($sessionIds === []) {
            return ['items' => [], 'total_tokens' => 0];
        }

        $tokenExpr = $this->tokenSql('m');

        $filled = DB::table('sessions.messages as m')
            ->whereIn('m.session_id', $sessionIds)
            ->selectRaw("
                m.session_id, m.sequence_number, m.timestamp,
                ({$tokenExpr}) as tokens,
                max(case when m.skill_name is not null then m.sequence_number end)
                    over (partition by m.session_id order by m.sequence_number
                          rows between unbounded preceding and 1 preceding) as active_skill_seq
            ");

        $rows = DB::query()
            ->fromSub($filled, 'f')
            ->leftJoin('sessions.messages as src', function ($join) {
                $join->on('src.session_id', '=', 'f.session_id')
                    ->on('src.sequence_number', '=', 'f.active_skill_seq');
            })
            ->where('f.timestamp', '>=', $since)
            ->where('f.timestamp', '<', $until)
            ->selectRaw('coalesce(src.skill_name, ?) as label, sum(f.tokens) as tokens', [self::NO_SKILL_LABEL])
            ->groupBy('label')
            ->get();

        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r->tokens;
        }

        $items = [];
        foreach ($rows as $r) {
            if ($r->label === self::NO_SKILL_LABEL) {
                continue;
            }
            $tokens = (int) $r->tokens;
            $items[] = [
                'label' => $r->label,
                'tokens' => $tokens,
                'pct' => $total > 0 ? (int) round($tokens / $total * 100) : 0,
                'is_plugin' => str_contains((string) $r->label, ':'),
            ];
        }
        usort($items, fn ($a, $b) => $b['tokens'] <=> $a['tokens']);

        return ['items' => $items, 'total_tokens' => $total];
    }

    /**
     * Plugin share (A1/A2 "category shares"): parsed from skill labels
     * containing ':' — no separate capture, per the spec's "parse, don't
     * re-capture" instruction.
     *
     * @param  array{items: list<array{label: string, tokens: int, pct: int, is_plugin: bool}>, total_tokens: int}  $skills
     * @return array{items: list<array{label: string, tokens: int, pct: int}>, total_tokens: int}
     */
    private function pluginShare(array $skills): array
    {
        $total = $skills['total_tokens'];
        $plugin = 0;
        $own = 0;
        foreach ($skills['items'] as $item) {
            if ($item['is_plugin']) {
                $plugin += $item['tokens'];
            } else {
                $own += $item['tokens'];
            }
        }

        $items = [];
        if ($plugin > 0) {
            $items[] = ['label' => 'plugin skills', 'tokens' => $plugin, 'pct' => $total > 0 ? (int) round($plugin / $total * 100) : 0];
        }
        if ($own > 0) {
            $items[] = ['label' => 'project skills', 'tokens' => $own, 'pct' => $total > 0 ? (int) round($own / $total * 100) : 0];
        }

        return ['items' => $items, 'total_tokens' => $total];
    }

    /**
     * MCP server share (A1) — parsed from `tool_names` (`mcp__<server>__<tool>`).
     * Deliberately a CALL-COUNT share, not a token share: `tool_names` carries
     * no per-call token cost, only which tools ran.
     *
     * @return array{items: list<array{label: string, calls: int, pct: int}>, total_calls: int}
     */
    private function mcpServerShares(Carbon $since, Carbon $until, ?string $account): array
    {
        $sessionIds = $this->mainSessionIdsInRange($since, $until, $account);
        if ($sessionIds === []) {
            return ['items' => [], 'total_calls' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));

        $rows = DB::select("
            SELECT split_part(tool, '__', 2) AS label, count(*) AS calls
            FROM sessions.messages m
            CROSS JOIN LATERAL unnest(m.tool_names) AS tool
            WHERE m.session_id IN ({$placeholders})
              AND m.timestamp >= ? AND m.timestamp < ?
              AND tool ~ '^mcp__'
            GROUP BY label
            ORDER BY calls DESC
        ", [...$sessionIds, $since->toDateTimeString(), $until->toDateTimeString()]);

        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r->calls;
        }

        $items = array_map(fn ($r) => [
            'label' => $r->label,
            'calls' => (int) $r->calls,
            'pct' => $total > 0 ? (int) round((int) $r->calls / $total * 100) : 0,
        ], $rows);

        return ['items' => $items, 'total_calls' => $total];
    }

    /** @return string[] */
    private function mainSessionIdsInRange(Carbon $since, Carbon $until, ?string $account): array
    {
        $query = DB::table('sessions.sessions')
            ->where('kind', 'main')
            ->where('last_activity_timestamp', '>=', $since)
            ->where('last_activity_timestamp', '<', $until);

        $this->accounts->constrainSessions($query, $account);

        return $query->pluck('session_id')->all();
    }

    /**
     * @param  iterable<object{label: string, tokens: int|string}>  $rows
     * @return array{items: list<array{label: string, tokens: int, pct: int}>, total_tokens: int}
     */
    private function tokenShares(iterable $rows): array
    {
        $total = 0;
        foreach ($rows as $r) {
            $total += (int) $r->tokens;
        }

        $items = [];
        foreach ($rows as $r) {
            $tokens = (int) $r->tokens;
            if ($tokens <= 0) {
                continue;
            }
            $items[] = [
                'label' => $r->label,
                'tokens' => $tokens,
                'pct' => $total > 0 ? (int) round($tokens / $total * 100) : 0,
            ];
        }
        usort($items, fn ($a, $b) => $b['tokens'] <=> $a['tokens']);

        return ['items' => $items, 'total_tokens' => $total];
    }
}
