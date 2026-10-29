<?php

namespace Tests\Feature\Api;

use App\Enums\QuotaFetchStatus;
use App\Models\QuotaSnapshot;
use App\Services\CostAnalyticsService;
use App\Services\QuotaUtilizationService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The happy path is the easy half. What matters here is that every failure mode
 * of an undocumented endpoint degrades to an explicit status instead of an
 * exception or — far worse — a stale number rendered as live.
 */
class QuotaUtilizationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.anthropic.com/api/oauth/usage';

    /** A credentials file shaped like Claude Code's, with a controllable expiry. */
    private function credentials(?int $expiresAtMs = null, bool $withToken = true): string
    {
        $path = tempnam(sys_get_temp_dir(), 'creds') . '.json';
        $oauth = ['expiresAt' => $expiresAtMs ?? (time() + 3600) * 1000];
        if ($withToken) {
            $oauth['accessToken'] = 'sk-ant-oat01-test';
        }
        file_put_contents($path, json_encode(['claudeAiOauth' => $oauth]));

        return $path;
    }

    private function service(?string $path = null, ?CostAnalyticsService $costs = null): QuotaUtilizationService
    {
        return new QuotaUtilizationService($path ?? $this->credentials(), 5, $costs);
    }

    /** A stand-in for the local half of a calibration pair. */
    private function localCosts(array $overrides = []): CostAnalyticsService
    {
        $mock = \Mockery::mock(CostAnalyticsService::class);
        $mock->shouldReceive('calibrationSample')->andReturn(array_merge([
            'open' => true,
            'window_started_at' => '2026-08-19T03:00:00+00:00',
            'tokens' => 300_000_000,
            'cost_usd' => 200.0,
            'indexed_through' => '2026-08-19T05:30:00+00:00',
            'scan_lag_s' => 30,
            'models' => [['model' => 'claude-opus-5', 'input' => 1, 'output' => 2,
                'cache_creation' => 3, 'cache_creation_1h' => 0, 'cache_read' => 4]],
            'account' => null,
        ], $overrides));

        return $mock;
    }

    /** Two readings in one window, with local spend frozen at each. */
    private function pair(int $pctA, int $pctB, int $tokensA, int $tokensB, array $opts = []): void
    {
        $window = $opts['window'] ?? '2026-08-19 03:00:00+00';
        $lag = $opts['lag'] ?? 30;

        foreach ([[$pctA, $tokensA, 0], [$pctB, $tokensB, 10]] as [$pct, $tokens, $offset]) {
            QuotaSnapshot::create([
                'fetched_at' => Carbon::parse('2026-08-19 05:00:00+00')->addMinutes($offset),
                'status' => QuotaFetchStatus::OK,
                'account' => 'cl',
                'five_hour_percent' => $pct,
                'local_window_started_at' => $opts['window_b'] ?? $window,
                'local_tokens' => $tokens,
                'local_cost_usd' => $tokens / 1_000_000,
                'local_scan_lag_s' => $lag,
            ]);
        }
    }

    /** The real payload shape, trimmed to what we read plus noise we must ignore. */
    private function payload(): array
    {
        return [
            'five_hour' => ['utilization' => 66.0],
            'seven_day' => ['utilization' => 70.0],
            // Feature-flagged buckets that are null in real responses — proof we
            // never touch the top-level keys.
            'tangelo' => null,
            'nimbus_quill' => ['utilization' => 0.0, 'resets_at' => null],
            'limits' => [
                ['kind' => 'session', 'group' => 'session', 'percent' => 66,
                    'severity' => 'normal', 'resets_at' => '2026-08-19T08:09:59+00:00',
                    'scope' => null, 'is_active' => false],
                ['kind' => 'weekly_all', 'group' => 'weekly', 'percent' => 70,
                    'severity' => 'normal', 'resets_at' => '2026-08-22T07:59:59+00:00',
                    'scope' => null, 'is_active' => true],
                ['kind' => 'weekly_scoped', 'group' => 'weekly', 'percent' => 18,
                    'severity' => 'normal', 'resets_at' => '2026-08-22T07:59:59+00:00',
                    'scope' => ['model' => ['id' => null, 'display_name' => 'Fable'], 'surface' => null],
                    'is_active' => false],
            ],
            'extra_usage' => ['is_enabled' => false, 'user_disabled' => true],
            'spend' => ['enabled' => false, 'used' => ['amount_minor' => 0, 'currency' => 'USD', 'exponent' => 2]],
        ];
    }

    public function test_it_fetches_and_persists_a_successful_reading(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);

        $snapshot = $this->service()->refresh();

        $this->assertSame(QuotaFetchStatus::OK, $snapshot->status);
        $this->assertSame(66, $snapshot->five_hour_percent);
        $this->assertSame(70, $snapshot->seven_day_percent);
        $this->assertSame('2026-08-19 08:09:59', $snapshot->five_hour_resets_at->utc()->format('Y-m-d H:i:s'));
        // The whole body is kept so a bucket we don't parse today is recoverable.
        $this->assertArrayHasKey('nimbus_quill', $snapshot->payload);
    }

    public function test_it_sends_the_oauth_token_and_beta_header(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);

        $this->service()->refresh();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-ant-oat01-test')
            && $request->hasHeader('anthropic-beta', 'oauth-2025-04-20'));
    }

    public function test_it_reads_limits_not_the_top_level_buckets(): void
    {
        // Top-level and limits[] deliberately disagree; limits[] must win.
        $payload = $this->payload();
        $payload['five_hour']['utilization'] = 99.0;

        Http::fake([self::ENDPOINT => Http::response($payload, 200)]);

        $this->assertSame(66, $this->service()->refresh()->five_hour_percent);
    }

    public function test_it_exposes_a_scoped_model_limit_by_display_name(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);
        $service = $this->service();

        $limits = $service->present($service->refresh())['limits'];

        $scoped = collect($limits)->firstWhere('kind', 'weekly_scoped');
        $this->assertSame('Fable', $scoped['scope_label']);
        $this->assertSame(18, $scoped['percent']);
    }

    public function test_an_expired_token_never_reaches_the_network(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);

        $snapshot = $this->service($this->credentials((time() - 60) * 1000))->refresh();

        $this->assertSame(QuotaFetchStatus::AUTH_EXPIRED, $snapshot->status);
        Http::assertNothingSent();
    }

    public function test_a_rejected_token_reports_auth_expired(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => 'unauthorized'], 401)]);

        $this->assertSame(QuotaFetchStatus::AUTH_EXPIRED, $this->service()->refresh()->status);
    }

    public function test_a_missing_credentials_file_is_unreachable_not_an_exception(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);

        $snapshot = $this->service('/nonexistent/.credentials.json')->refresh();

        $this->assertSame(QuotaFetchStatus::UNREACHABLE, $snapshot->status);
        $this->assertStringContainsString('credentials not found', $snapshot->reason);
        Http::assertNothingSent();
    }

    public function test_credentials_without_a_token_are_unreachable(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);

        $snapshot = $this->service($this->credentials(null, withToken: false))->refresh();

        $this->assertSame(QuotaFetchStatus::UNREACHABLE, $snapshot->status);
    }

    public function test_a_server_error_is_recorded_as_unreachable(): void
    {
        Http::fake([self::ENDPOINT => Http::response('nope', 503)]);

        $snapshot = $this->service()->refresh();

        $this->assertSame(QuotaFetchStatus::UNREACHABLE, $snapshot->status);
        $this->assertStringContainsString('503', $snapshot->reason);
    }

    public function test_a_changed_response_shape_is_malformed_and_keeps_the_body(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['something_else' => true], 200)]);

        $snapshot = $this->service()->refresh();

        $this->assertSame(QuotaFetchStatus::MALFORMED, $snapshot->status);
        $this->assertNull($snapshot->five_hour_percent);
        // Kept so the shape change is diagnosable after the fact.
        $this->assertSame(['something_else' => true], $snapshot->payload);
    }

    public function test_a_failed_reading_presents_no_numbers(): void
    {
        Http::fake([self::ENDPOINT => Http::response('nope', 503)]);
        $service = $this->service();

        $data = $service->present($service->refresh());

        $this->assertFalse($data['available']);
        $this->assertSame([], $data['limits']);
        $this->assertNull($data['five_hour']);
        // Even a failure carries its timestamp — the UI must never show an
        // undated reading.
        $this->assertNotNull($data['fetched_at']);
    }

    public function test_present_handles_never_having_fetched(): void
    {
        $data = $this->service()->present(null);

        $this->assertFalse($data['available']);
        $this->assertSame('never_fetched', $data['status']);
        $this->assertNull($data['fetched_at']);
    }

    public function test_latest_returns_the_most_recent_reading_of_any_kind(): void
    {
        QuotaSnapshot::create([
            'fetched_at' => now()->subHour(), 'status' => QuotaFetchStatus::OK,
            'account' => 'cl', 'five_hour_percent' => 10,
        ]);
        QuotaSnapshot::create([
            'fetched_at' => now(), 'status' => QuotaFetchStatus::UNREACHABLE,
            'account' => 'cl', 'reason' => 'down',
        ]);

        // A later failure must not be masked by an earlier success.
        $this->assertSame(QuotaFetchStatus::UNREACHABLE, $this->service()->latest()->status);
    }

    public function test_the_refresh_endpoint_returns_the_reading(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);
        config(['services.claude_usage.credentials_path' => $this->credentials()]);

        $this->postJson('/api/analytics/quota/refresh')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.five_hour.percent', 66)
            ->assertJsonCount(3, 'data.limits');
    }

    public function test_the_latest_endpoint_never_calls_anthropic(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);

        $this->getJson('/api/analytics/quota/latest')
            ->assertOk()
            ->assertJsonPath('data.status', 'never_fetched');

        Http::assertNothingSent();
    }

    // =========================================================
    // Calibration capture — pairing a real reading with local spend
    // =========================================================

    public function test_it_freezes_local_spend_into_the_reading(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);

        $snapshot = $this->service(null, $this->localCosts())->refresh();

        $this->assertSame(300_000_000, $snapshot->local_tokens);
        $this->assertSame(200.0, $snapshot->local_cost_usd);
        $this->assertSame(30, $snapshot->local_scan_lag_s);
        // Token CLASSES, not a pre-weighted number — the quota's weighting is
        // unknown, so the components must survive for a later fit.
        $this->assertSame(2, $snapshot->local_breakdown[0]['output']);
    }

    public function test_local_spend_is_captured_even_when_the_fetch_fails(): void
    {
        Http::fake([self::ENDPOINT => Http::response('nope', 503)]);

        $snapshot = $this->service(null, $this->localCosts())->refresh();

        $this->assertSame(QuotaFetchStatus::UNREACHABLE, $snapshot->status);
        // The local half is still a valid observation of the window.
        $this->assertSame(300_000_000, $snapshot->local_tokens);
    }

    public function test_a_broken_local_sample_never_breaks_the_fetch(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);
        $costs = \Mockery::mock(CostAnalyticsService::class);
        $costs->shouldReceive('calibrationSample')->andThrow(new \RuntimeException('db down'));

        $snapshot = $this->service(null, $costs)->refresh();

        $this->assertSame(QuotaFetchStatus::OK, $snapshot->status);
        $this->assertSame(66, $snapshot->five_hour_percent);
        $this->assertNull($snapshot->local_tokens);
    }

    public function test_it_measures_drift_between_our_window_and_the_real_reset(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);
        $service = $this->service(null, $this->localCosts());

        $data = $service->present($service->refresh());

        // Local window 03:00 + 5h = 08:00; Anthropic resets at 08:09:59.
        $this->assertSame(-599, $data['local']['window_drift_s']);
    }

    public function test_calibration_pairs_consecutive_readings_in_one_window(): void
    {
        $this->pair(60, 64, 300_000_000, 320_000_000);

        $result = $this->service()->calibration();

        $this->assertCount(1, $result['pairs']);
        $pair = $result['pairs'][0];
        $this->assertSame(4, $pair['delta_percent']);
        $this->assertSame(20_000_000, $pair['delta_tokens']);
        $this->assertSame(5_000_000, $pair['tokens_per_percent']);
    }

    public function test_calibration_never_divides_by_an_unmoved_percentage(): void
    {
        // Real usage moved locally but the reported percentage did not tick.
        $this->pair(60, 60, 300_000_000, 310_000_000);

        $pair = $this->service()->calibration()['pairs'][0];

        $this->assertNull($pair['tokens_per_percent']);
        $this->assertNull($pair['usd_per_percent']);
    }

    public function test_calibration_excludes_stale_local_counts(): void
    {
        // A count 20 minutes behind describes a different moment than the
        // reading beside it — that is a missing observation, not a small error.
        $this->pair(60, 64, 300_000_000, 320_000_000, ['lag' => 1200]);

        $result = $this->service()->calibration();

        $this->assertSame(0, $result['usable_readings']);
        $this->assertSame([], $result['pairs']);
    }

    public function test_calibration_does_not_pair_across_a_window_reset(): void
    {
        QuotaSnapshot::create([
            'fetched_at' => Carbon::parse('2026-08-19 07:55:00+00'),
            'status' => QuotaFetchStatus::OK, 'five_hour_percent' => 90,
            'local_window_started_at' => '2026-08-19 03:00:00+00',
            'local_tokens' => 400_000_000, 'local_cost_usd' => 400, 'local_scan_lag_s' => 20,
        ]);
        QuotaSnapshot::create([
            'fetched_at' => Carbon::parse('2026-08-19 08:15:00+00'),
            'status' => QuotaFetchStatus::OK, 'five_hour_percent' => 3,
            'local_window_started_at' => '2026-08-19 08:00:00+00',
            'local_tokens' => 5_000_000, 'local_cost_usd' => 5, 'local_scan_lag_s' => 20,
        ]);

        // The percentage went backwards and the counter restarted; a delta
        // across that boundary is meaningless.
        $this->assertSame([], $this->service()->calibration()['pairs']);
    }

    public function test_calibration_refuses_to_pretend_it_has_enough_data(): void
    {
        $this->pair(60, 64, 300_000_000, 320_000_000);

        $result = $this->service()->calibration();

        $this->assertStringContainsString('Not enough pairs', $result['verdict']);
        // No fitted constant is returned — one pair from one window would look
        // like a calibration and would not be one.
        $this->assertArrayNotHasKey('tokens_per_percent', $result);
    }

    public function test_the_calibration_endpoint_is_read_only(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->payload(), 200)]);

        $this->getJson('/api/analytics/quota/calibration')
            ->assertOk()
            ->assertJsonPath('data.usable_readings', 0);

        Http::assertNothingSent();
    }
}
