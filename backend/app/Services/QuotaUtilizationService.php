<?php

namespace App\Services;

use App\Enums\QuotaFetchStatus;
use App\Models\QuotaSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Anthropic's server-reported quota — the only authoritative percentage.
 *
 * Jarvis cannot compute a quota percentage from local token counts; that was
 * attempted, measured against ground truth, and came out 12x wrong.
 * It can, however, be
 * *fetched*: Claude Code itself reads GET /api/oauth/usage with the OAuth token
 * it stores on disk, and so can we.
 *
 * Deliberately manual — there is no poller and no schedule entry. The user
 * clicks a button. Polling an undocumented Anthropic endpoint on a timer is a
 * cost we have no reason to pay for a number that changes slowly.
 *
 * Every failure degrades to an explicit status rather than throwing, for the
 * same reason HostMetricsService does: a stale number rendered as live is worse
 * than a visible outage.
 */
class QuotaUtilizationService
{
    /** Discovered in the CLI binary's string table: `fetchUtilization: GET /api/oauth/usage`. */
    private const ENDPOINT = 'https://api.anthropic.com/api/oauth/usage';

    /** The OAuth scheme Claude Code presents. Without it the token is not accepted. */
    private const BETA_HEADER = 'oauth-2025-04-20';

    /**
     * How stale the local half of a pair may be and still count.
     *
     * Sized to the observed scan cadence (median gap ~15 min), not to what
     * would be ideal — a tighter bound rejects nearly every pair. Anything
     * looser than this is comparing an instant reading to a different quarter
     * hour, which is not a measurement.
     */
    public const DEFAULT_MAX_LAG_S = 900;

    /** Why readToken() failed, so the caller can report something specific. */
    private ?string $tokenReason = null;

    public function __construct(
        private ?string $credentialsPath = null,
        private ?int $timeout = null,
        private ?CostAnalyticsService $costs = null,
        private ?QuotaWindowService $windows = null,
    ) {
        $this->credentialsPath ??= (string) config('services.claude_usage.credentials_path');
        $this->timeout ??= (int) config('services.claude_usage.timeout', 10);
        $this->costs ??= app(CostAnalyticsService::class);
        $this->windows ??= app(QuotaWindowService::class);
    }

    /**
     * Fetch once and persist the result — successes and failures alike.
     */
    public function refresh(string $account = 'cl'): QuotaSnapshot
    {
        $result = $this->fetch();
        $local = $this->localSample();

        return QuotaSnapshot::create([
            'fetched_at' => now(),
            'status' => $result['status'],
            'account' => $account,
            'reason' => $result['reason'],
            'five_hour_percent' => $result['five_hour']['percent'] ?? null,
            'seven_day_percent' => $result['seven_day']['percent'] ?? null,
            'five_hour_resets_at' => $result['five_hour']['resets_at'] ?? null,
            'seven_day_resets_at' => $result['seven_day']['resets_at'] ?? null,
            'payload' => $result['payload'],
            'local_window_started_at' => $local['window_started_at'] ?? null,
            'local_tokens' => $local['tokens'] ?? null,
            'local_cost_usd' => $local['cost_usd'] ?? null,
            'local_indexed_through' => $local['indexed_through'] ?? null,
            'local_scan_lag_s' => $local['scan_lag_s'] ?? null,
            'local_breakdown' => $local['models'] ?? null,
        ]);
    }

    /**
     * Local spend at this instant, frozen alongside the real reading.
     *
     * A real percentage is only half a calibration pair; the other half is what
     * we had counted locally at the same moment, and it cannot be reconstructed
     * afterwards. Captured for FAILED fetches too — the local half is still a
     * valid observation of the window, and discarding it would make outages
     * blind spots in the series.
     *
     * Never allowed to break a fetch: the reading is the product here, the
     * sample is bookkeeping.
     */
    private function localSample(): array
    {
        try {
            return $this->costs->calibrationSample();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** The most recent reading, or null if we have never fetched. */
    public function latest(?string $account = null): ?QuotaSnapshot
    {
        return QuotaSnapshot::query()
            ->when($account !== null, fn ($q) => $q->where('account', $account))
            ->latestFirst()
            ->first();
    }

    /**
     * Shape a snapshot for the API. Always carries its own age so the UI can
     * never render a reading without saying how old it is.
     *
     * @return array<string,mixed>
     */
    public function present(?QuotaSnapshot $snapshot): array
    {
        if ($snapshot === null) {
            return [
                'available' => false,
                'status' => 'never_fetched',
                'reason' => 'No reading yet — fetch to pull your current quota from Anthropic.',
                'fetched_at' => null,
                'age_s' => null,
                'account' => null,
                'limits' => [],
                'five_hour' => null,
                'seven_day' => null,
                'extra_usage' => null,
                'spend' => null,
            ];
        }

        $ok = $snapshot->status->isOk();
        $payload = $snapshot->payload ?? [];

        return [
            'available' => $ok,
            'status' => $snapshot->status->value,
            'reason' => $snapshot->reason,
            'fetched_at' => $snapshot->fetched_at?->toIso8601String(),
            'age_s' => $snapshot->fetched_at ? (int) max(0, round(now()->diffInSeconds($snapshot->fetched_at, true))) : null,
            'account' => $snapshot->account,
            'limits' => $ok ? $this->limits($payload) : [],
            'five_hour' => $ok ? $this->bucket($snapshot->five_hour_percent, $snapshot->five_hour_resets_at) : null,
            'seven_day' => $ok ? $this->bucket($snapshot->seven_day_percent, $snapshot->seven_day_resets_at) : null,
            'extra_usage' => $ok ? $this->extraUsage($payload) : null,
            'spend' => $ok ? $this->spend($payload) : null,
            'local' => $this->localBlock($snapshot),
        ];
    }

    /**
     * The local half of the pair, surfaced so staleness is visible rather than
     * buried in a column. `scan_lag_s` is the honest caveat on any comparison
     * between these numbers and the real percentage above them.
     */
    private function localBlock(QuotaSnapshot $snapshot): ?array
    {
        if ($snapshot->local_tokens === null) {
            return null;
        }

        return [
            'tokens' => $snapshot->local_tokens,
            'cost_usd' => $snapshot->local_cost_usd,
            'window_started_at' => $snapshot->local_window_started_at?->toIso8601String(),
            'indexed_through' => $snapshot->local_indexed_through?->toIso8601String(),
            'scan_lag_s' => $snapshot->local_scan_lag_s,
            // How far our inferred window boundary sits from Anthropic's real
            // one. Non-zero means every elapsed/burn/projection figure on the
            // local strip is skewed by that much.
            'window_drift_s' => $this->windowDrift($snapshot),
        ];
    }

    /**
     * Local window end minus the real reset time, in seconds.
     *
     * Positive = we think the window runs longer than it does.
     */
    private function windowDrift(QuotaSnapshot $snapshot): ?int
    {
        if ($snapshot->local_window_started_at === null || $snapshot->five_hour_resets_at === null) {
            return null;
        }

        // Block length comes from QuotaWindowService so there is one definition
        // of what a window is, not two that can drift apart.
        $localEnd = $snapshot->local_window_started_at->copy()
            ->addHours($this->windows->blockHours());

        return (int) round($localEnd->diffInSeconds($snapshot->five_hour_resets_at, false)) * -1;
    }

    /**
     * Consecutive readings inside the same window, as calibration pairs.
     *
     * This is the analysis surface for the open question: does local spend
     * predict the real percentage well enough to advance it between fetches?
     * Only pairs where BOTH readings had a fresh index are usable — a stale
     * local count describes a different moment than the reading beside it.
     *
     * @return array<string,mixed>
     */
    public function calibration(int $maxLagSeconds = self::DEFAULT_MAX_LAG_S, int $limit = 200): array
    {
        $rows = QuotaSnapshot::query()
            ->calibratable($maxLagSeconds)
            ->orderBy('fetched_at')
            ->limit($limit)
            ->get();

        $pairs = [];

        foreach ($rows as $i => $row) {
            $prev = $rows[$i - 1] ?? null;
            if ($prev === null) {
                continue;
            }

            // A window reset breaks the chain: the percentage went backwards and
            // the token counter restarted, so the delta is meaningless.
            $sameWindow = $prev->local_window_started_at !== null
                && $row->local_window_started_at !== null
                && $prev->local_window_started_at->equalTo($row->local_window_started_at);

            if (! $sameWindow || $row->five_hour_percent === null || $prev->five_hour_percent === null) {
                continue;
            }

            $dPct = $row->five_hour_percent - $prev->five_hour_percent;
            $dTokens = (int) $row->local_tokens - (int) $prev->local_tokens;
            $dCost = round((float) $row->local_cost_usd - (float) $prev->local_cost_usd, 4);

            $pairs[] = [
                'from' => $prev->fetched_at?->toIso8601String(),
                'to' => $row->fetched_at?->toIso8601String(),
                'minutes' => $prev->fetched_at && $row->fetched_at
                    ? (int) round($prev->fetched_at->diffInMinutes($row->fetched_at, true))
                    : null,
                'delta_percent' => $dPct,
                'delta_tokens' => $dTokens,
                'delta_cost_usd' => $dCost,
                // The two candidate calibration constants. Null when the
                // percentage did not move — a zero delta says nothing about
                // the rate, and dividing by it would manufacture a number.
                'tokens_per_percent' => $dPct > 0 ? (int) round($dTokens / $dPct) : null,
                'usd_per_percent' => $dPct > 0 ? round($dCost / $dPct, 4) : null,
                'max_scan_lag_s' => max((int) $prev->local_scan_lag_s, (int) $row->local_scan_lag_s),
            ];
        }

        return [
            'pairs' => $pairs,
            'usable_readings' => $rows->count(),
            'max_scan_lag_s' => $maxLagSeconds,
            // Deliberately no fitted constant yet. A handful of pairs from one
            // window would look like a calibration and would not be one.
            'verdict' => count($pairs) < 10
                ? 'Not enough pairs yet — keep fetching across several windows.'
                : 'Enough pairs to look at; check whether the ratios cluster before trusting one.',
        ];
    }

    /**
     * One HTTP call. Returns a normalized result; never throws.
     *
     * @return array{status:QuotaFetchStatus,reason:?string,payload:?array,five_hour:?array,seven_day:?array}
     */
    private function fetch(): array
    {
        $token = $this->readToken();

        if ($token instanceof QuotaFetchStatus) {
            return $this->failure($token, $this->tokenReason);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'anthropic-beta' => self::BETA_HEADER,
                'Content-Type' => 'application/json',
            ])->timeout($this->timeout)->get(self::ENDPOINT);
        } catch (Throwable $e) {
            return $this->failure(QuotaFetchStatus::UNREACHABLE, 'Request failed: ' . $e->getMessage());
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return $this->failure(
                QuotaFetchStatus::AUTH_EXPIRED,
                'Anthropic rejected the token (HTTP ' . $response->status() . ') — run any `claude` command to refresh it.',
            );
        }

        if (! $response->successful()) {
            return $this->failure(
                QuotaFetchStatus::UNREACHABLE,
                'Usage endpoint returned HTTP ' . $response->status() . '.',
            );
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['limits']) || ! is_array($payload['limits'])) {
            return $this->failure(
                QuotaFetchStatus::MALFORMED,
                'Response carried no `limits` array — the endpoint shape may have changed.',
                is_array($payload) ? $payload : null,
            );
        }

        return [
            'status' => QuotaFetchStatus::OK,
            'reason' => null,
            'payload' => $payload,
            'five_hour' => $this->extract($payload, 'session'),
            'seven_day' => $this->extract($payload, 'weekly_all'),
        ];
    }

    /**
     * The OAuth access token Claude Code stores, read fresh every call.
     *
     * Never cached (the CLI rotates it) and never refreshed by us — running the
     * refresh flow out-of-band could invalidate the token the CLI is actively
     * using. An expired token is a normal state we report, not an error we fix.
     *
     * @return string|QuotaFetchStatus the token, or the failure it produced
     */
    private function readToken(): string|QuotaFetchStatus
    {
        $path = $this->credentialsPath;

        if (! is_file($path) || ! is_readable($path)) {
            $this->tokenReason = 'Claude Code credentials not found at ' . $path . '.';

            return QuotaFetchStatus::UNREACHABLE;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        $oauth = is_array($data) ? ($data['claudeAiOauth'] ?? null) : null;
        $token = is_array($oauth) ? ($oauth['accessToken'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            $this->tokenReason = 'Credentials file carries no claudeAiOauth.accessToken.';

            return QuotaFetchStatus::UNREACHABLE;
        }

        // expiresAt is epoch milliseconds. Checking it locally saves a round trip
        // and gives a clearer message than a bare 401.
        $expiresAt = $oauth['expiresAt'] ?? null;
        if (is_numeric($expiresAt) && (float) $expiresAt / 1000 < time()) {
            $this->tokenReason = QuotaFetchStatus::AUTH_EXPIRED->label();

            return QuotaFetchStatus::AUTH_EXPIRED;
        }

        return $token;
    }

    /** @return array{status:QuotaFetchStatus,reason:?string,payload:?array,five_hour:null,seven_day:null} */
    private function failure(QuotaFetchStatus $status, ?string $reason, ?array $payload = null): array
    {
        return [
            'status' => $status,
            'reason' => $reason ?? $status->label(),
            'payload' => $payload,
            'five_hour' => null,
            'seven_day' => null,
        ];
    }

    /**
     * Pull one limit kind out for denormalisation.
     *
     * Reads `limits[]` rather than the top-level `five_hour` / `seven_day` keys:
     * the payload also carries feature-flagged buckets under codenames
     * (`tangelo`, `nimbus_quill`, …) and `limits[]` is the normalized view that
     * already folds in whichever are live.
     *
     * @return array{percent:?int,resets_at:?Carbon}|null
     */
    private function extract(array $payload, string $kind): ?array
    {
        foreach ($payload['limits'] as $limit) {
            if (is_array($limit) && ($limit['kind'] ?? null) === $kind) {
                return [
                    'percent' => is_numeric($limit['percent'] ?? null) ? (int) round((float) $limit['percent']) : null,
                    'resets_at' => $this->parseTime($limit['resets_at'] ?? null),
                ];
            }
        }

        return null;
    }

    /**
     * `limits[]` verbatim but normalized — rendered generically by the UI so a
     * bucket kind we have never seen appears on its own rather than breaking.
     *
     * @return list<array<string,mixed>>
     */
    private function limits(array $payload): array
    {
        return collect($payload['limits'] ?? [])
            ->filter(fn ($l) => is_array($l) && isset($l['kind']))
            ->map(fn (array $l) => [
                'kind' => (string) $l['kind'],
                'group' => isset($l['group']) ? (string) $l['group'] : null,
                'percent' => is_numeric($l['percent'] ?? null) ? (int) round((float) $l['percent']) : null,
                // Unknown severities pass through as-is; the UI falls back to
                // neutral styling rather than throwing on a new value.
                'severity' => isset($l['severity']) ? (string) $l['severity'] : 'normal',
                'resets_at' => $this->parseTime($l['resets_at'] ?? null)?->toIso8601String(),
                'is_active' => (bool) ($l['is_active'] ?? false),
                // scope.model.id is null on every reading observed — display_name
                // is the only usable model label here.
                'scope_label' => $this->scopeLabel($l['scope'] ?? null),
            ])
            ->values()
            ->all();
    }

    private function scopeLabel(mixed $scope): ?string
    {
        if (! is_array($scope)) {
            return null;
        }

        $model = $scope['model']['display_name'] ?? null;
        $surface = $scope['surface'] ?? null;

        return is_string($model) ? $model : (is_string($surface) ? $surface : null);
    }

    /** @return array{percent:?int,resets_at:?string} */
    private function bucket(?int $percent, ?Carbon $resetsAt): array
    {
        return [
            'percent' => $percent,
            'resets_at' => $resetsAt?->toIso8601String(),
        ];
    }

    /**
     * Extra usage credits. Present but disabled on this account — surfaced only
     * when actually enabled so the panel stays about quota.
     */
    private function extraUsage(array $payload): ?array
    {
        $extra = $payload['extra_usage'] ?? null;
        if (! is_array($extra) || ! ($extra['is_enabled'] ?? false)) {
            return null;
        }

        return [
            'utilization' => is_numeric($extra['utilization'] ?? null) ? (float) $extra['utilization'] : null,
            'used_credits' => $extra['used_credits'] ?? null,
            'monthly_limit' => $extra['monthly_limit'] ?? null,
            'currency' => $extra['currency'] ?? null,
        ];
    }

    /** Pay-as-you-go spend. Null unless the account actually has it enabled. */
    private function spend(array $payload): ?array
    {
        $spend = $payload['spend'] ?? null;
        if (! is_array($spend) || ! ($spend['enabled'] ?? false)) {
            return null;
        }

        $used = $spend['used'] ?? null;
        $exponent = is_array($used) && is_numeric($used['exponent'] ?? null) ? (int) $used['exponent'] : 2;
        $minor = is_array($used) && is_numeric($used['amount_minor'] ?? null) ? (float) $used['amount_minor'] : null;

        return [
            'used' => $minor !== null ? round($minor / (10 ** $exponent), 2) : null,
            'currency' => is_array($used) ? ($used['currency'] ?? 'USD') : 'USD',
            'percent' => is_numeric($spend['percent'] ?? null) ? (int) round((float) $spend['percent']) : null,
        ];
    }

    private function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
