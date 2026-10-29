<?php

namespace App\Services;

use App\Models\QuotaSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 5-hour rate-limit window estimates (the number that matters on a Claude
 * subscription), computed from the local session index — no billing API.
 *
 * Blocks follow the ccusage convention: a block opens at the UTC-hour-floor
 * of the first activity after a >=5h gap and spans exactly 5 hours. All
 * figures are local estimates; the messages index refreshes on the scan
 * schedule, so consumers must surface `as_of`.
 *
 * EXCEPT for the open window, which is anchored to the truth when we have it.
 * Anthropic reports the real `resets_at` on every quota fetch, and the
 * hour-floor convention is provably off: measured 2026-08-19, our inferred
 * window ended 08:00 UTC while the real reset was 08:09:59 — ten minutes of
 * skew in `elapsed_pct`, `resets_in_minutes`, burn and projection. When a
 * fetched reset time is still in the future it defines the open window and the
 * hour-floor guess is discarded. History stays hour-floored: there are no
 * anchors for past windows and never will be.
 */
class QuotaWindowService
{
    private const BLOCK_HOURS = 5;

    /** How far back to look for the observed ceiling (max block total). */
    private const HISTORY_DAYS = 30;

    /** Burn rate lookback within the current block. */
    private const BURN_LOOKBACK_MINUTES = 60;

    public function __construct(
        private SessionAccountService $accounts,
    ) {}

    public function current(): array
    {
        $hours = $this->hourlyTokens(self::HISTORY_DAYS);

        $asOf = $hours->isNotEmpty() ? Carbon::parse($hours->last()->last_activity)->toIso8601String() : null;

        $blocks = $this->foldIntoBlocks($hours);
        if ($blocks === []) {
            return ['data' => null, 'meta' => ['as_of' => $asOf]];
        }

        // Through currentBlock() so this endpoint and the /costs strip agree on
        // where the window starts — including when it is server-anchored.
        $current = $this->currentBlock();
        if ($current === null) {
            return ['data' => null, 'meta' => ['as_of' => $asOf]];
        }

        $endsAt = $current['started_at']->copy()->addHours(self::BLOCK_HOURS);

        // Ceiling excludes the current open block — it must never count toward
        // its own record, or pct_of_ceiling flattens exactly when a new high
        // is set. Only closed (fully elapsed) blocks contribute.
        $closedBlocks = array_slice($blocks, 0, -1);
        $ceiling = $closedBlocks !== [] ? max(array_column($closedBlocks, 'tokens')) : null;

        $elapsedMinutes = max(1, (int) round($current['started_at']->diffInMinutes(now())));
        $blockMinutes = self::BLOCK_HOURS * 60;

        $burn = $this->burnPerMinute();
        $remainingMinutes = max(0, $blockMinutes - $elapsedMinutes);
        $projected = $current['tokens'] + (int) round($burn * $remainingMinutes);

        return [
            'data' => [
                'window_started_at' => $current['started_at']->toIso8601String(),
                'window_ends_at' => $endsAt->toIso8601String(),
                'elapsed_pct' => (int) min(100, round($elapsedMinutes / $blockMinutes * 100)),
                'tokens_used' => $current['tokens'],
                'burn_tokens_per_min' => (int) round($burn),
                'projected_total' => $projected,
                'ceiling_tokens' => $ceiling !== null && $ceiling > 0 ? $ceiling : null,
                'pct_of_ceiling' => $ceiling !== null && $ceiling > 0
                    ? (int) min(999, round($current['tokens'] / $ceiling * 100))
                    : null,
                'as_of' => $asOf,
                'boundary_source' => $current['source'] ?? 'hour_floor',
                'provenance' => 'local_estimate',
            ],
            'meta' => ['as_of' => $asOf],
        ];
    }

    /**
     * The 5-hour blocks over the lookback window, oldest first. Public so cost
     * analytics measures "a window" exactly the same way this service does —
     * one block convention, not two that drift apart.
     *
     * @return array<int, array{started_at: Carbon, tokens: int}>
     */
    public function blocks(int $days = self::HISTORY_DAYS, ?string $account = null): array
    {
        return $this->foldIntoBlocks($this->hourlyTokens($days, $account));
    }

    /**
     * The currently open block, or null when the last block has already closed.
     *
     * Prefers Anthropic's own window boundary over the hour-floor guess. When
     * anchored, tokens are re-summed from the true start rather than reused —
     * the guessed start is typically earlier, so keeping its total would count
     * spend from before the window opened.
     */
    public function currentBlock(?string $account = null): ?array
    {
        $anchor = $this->serverWindowStart();

        if ($anchor !== null) {
            return [
                'started_at' => $anchor,
                'tokens' => $this->tokensSince($anchor, $account),
                'source' => 'server_anchor',
            ];
        }

        $blocks = $this->blocks(self::HISTORY_DAYS, $account);
        if ($blocks === []) {
            return null;
        }

        $current = end($blocks);
        $endsAt = $current['started_at']->copy()->addHours(self::BLOCK_HOURS);

        return $endsAt->isPast() ? null : $current + ['source' => 'hour_floor'];
    }

    /**
     * The open window's true start, derived from the last fetched reset time.
     *
     * A reset time still in the future identifies a window that is still open —
     * that is what makes an old fetch usable and a fetch from a closed window
     * correctly ignored. No freshness check beyond that is needed or wanted: a
     * window's identity is its reset time, not when we happened to ask.
     */
    private function serverWindowStart(): ?Carbon
    {
        $resetsAt = QuotaSnapshot::query()
            ->successful()
            ->whereNotNull('five_hour_resets_at')
            ->where('five_hour_resets_at', '>', now())
            ->orderByDesc('five_hour_resets_at')
            ->value('five_hour_resets_at');

        return $resetsAt ? Carbon::parse($resetsAt)->subHours(self::BLOCK_HOURS) : null;
    }

    /** Window tokens measured from an exact instant, not an hour boundary. */
    private function tokensSince(Carbon $since, ?string $account = null): int
    {
        $query = DB::table('sessions.messages')->where('timestamp', '>=', $since);

        $this->accounts->constrainMessages($query, $account, 'sessions.messages.session_id');

        return (int) $query->selectRaw('
            coalesce(sum(input_tokens), 0)
                + coalesce(sum(output_tokens), 0)
                + coalesce(sum(cache_creation_tokens), 0)
                + coalesce(sum(cache_read_tokens), 0) as tokens
        ')->value('tokens');
    }

    public function blockHours(): int
    {
        return self::BLOCK_HOURS;
    }

    /**
     * Fold hourly sums into 5h blocks: a new block opens when an active hour
     * starts at/after the previous block's end.
     *
     * @return array<int, array{started_at: Carbon, tokens: int}>
     */
    private function foldIntoBlocks($hours): array
    {
        $blocks = [];
        $currentBlock = null;

        foreach ($hours as $row) {
            $hour = Carbon::parse($row->hour);
            if ($currentBlock === null || $hour->gte($currentBlock['started_at']->copy()->addHours(self::BLOCK_HOURS))) {
                if ($currentBlock !== null) {
                    $blocks[] = $currentBlock;
                }
                $currentBlock = ['started_at' => $hour, 'tokens' => 0];
            }
            $currentBlock['tokens'] += (int) $row->tokens;
        }
        if ($currentBlock !== null) {
            $blocks[] = $currentBlock;
        }

        return $blocks;
    }

    /**
     * Hourly token sums over the lookback, oldest first. `$account` narrows to
     * one Claude account; null = all accounts, the historical behaviour.
     */
    private function hourlyTokens(int $days, ?string $account = null)
    {
        $query = DB::table('sessions.messages')
            ->where('timestamp', '>=', now()->subDays($days));

        $this->accounts->constrainMessages($query, $account, 'sessions.messages.session_id');

        return $query
            ->groupByRaw("date_trunc('hour', timestamp)")
            ->orderByRaw("date_trunc('hour', timestamp)")
            ->selectRaw("
                date_trunc('hour', timestamp) as hour,
                coalesce(sum(input_tokens), 0)
                    + coalesce(sum(output_tokens), 0)
                    + coalesce(sum(cache_creation_tokens), 0)
                    + coalesce(sum(cache_read_tokens), 0) as tokens,
                max(timestamp) as last_activity
            ")
            ->get();
    }

    private function burnPerMinute(): float
    {
        $tokens = (int) DB::table('sessions.messages')
            ->where('timestamp', '>=', now()->subMinutes(self::BURN_LOOKBACK_MINUTES))
            ->selectRaw("
                coalesce(sum(input_tokens), 0)
                    + coalesce(sum(output_tokens), 0)
                    + coalesce(sum(cache_creation_tokens), 0)
                    + coalesce(sum(cache_read_tokens), 0) as tokens
            ")
            ->value('tokens');

        return $tokens / self::BURN_LOOKBACK_MINUTES;
    }
}
