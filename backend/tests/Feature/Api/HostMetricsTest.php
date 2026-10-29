<?php // backend/tests/Feature/Api/HostMetricsTest.php
namespace Tests\Feature\Api;

use App\Services\HostMetricsService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The tray agent is an optional, external, crash-prone dependency, so every
 * unavailable path matters as much as the happy one — a panel that silently
 * renders frozen numbers is worse than one that says the agent is down.
 */
class HostMetricsTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir() . '/jarvis-host-test-' . getmypid() . '.json';
        Cache::flush();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function service(int $staleAfter = 30): HostMetricsService
    {
        return new HostMetricsService($this->path, $staleAfter);
    }

    private function write(array $overrides = []): void
    {
        file_put_contents($this->path, json_encode(array_replace_recursive([
            'schema' => 1,
            'ts' => microtime(true),
            'host' => 'TEST-PC',
            'cpu_pct' => 12.5,
            'cpu_cores' => 16,
            'mem' => ['total_mb' => 32042, 'available_mb' => 10000, 'used_mb' => 22042, 'pct' => 68.8],
            'commit' => ['used_mb' => 30000, 'limit_mb' => 54570, 'pct' => 55.0],
            'wsl_mb' => 8000,
            'top' => [['name' => 'vmmemWSL', 'mb' => 8000]],
        ], $overrides)));
    }

    public function test_missing_file_reports_offline_not_an_error(): void
    {
        $snap = $this->service()->snapshot();

        $this->assertFalse($snap['available']);
        $this->assertSame('offline', $snap['status']);
        $this->assertNull($snap['metrics']);
        $this->assertNotNull($snap['reason']);
    }

    public function test_malformed_json_reports_offline(): void
    {
        file_put_contents($this->path, '{not json');

        $snap = $this->service()->snapshot();

        $this->assertFalse($snap['available']);
        $this->assertSame('offline', $snap['status']);
    }

    public function test_truncated_payload_missing_keys_reports_offline(): void
    {
        // A half-written file would parse but lack the fields we depend on.
        file_put_contents($this->path, json_encode(['schema' => 1, 'ts' => microtime(true)]));

        $snap = $this->service()->snapshot();

        $this->assertFalse($snap['available']);
        $this->assertSame('offline', $snap['status']);
    }

    public function test_fresh_file_is_available_with_metrics(): void
    {
        $this->write();

        $snap = $this->service()->snapshot();

        $this->assertTrue($snap['available']);
        $this->assertSame('ok', $snap['status']);
        $this->assertSame('TEST-PC', $snap['hostname']);
        $this->assertSame(32042, $snap['metrics']['mem_total_mb']);
        $this->assertSame(8000, $snap['metrics']['wsl_mb']);
        $this->assertSame('ok', $snap['pressure']);
    }

    public function test_old_file_reports_stale(): void
    {
        $this->write(['ts' => microtime(true) - 300]);

        $snap = $this->service()->snapshot();

        $this->assertFalse($snap['available']);
        $this->assertSame('stale', $snap['status']);
        $this->assertStringContainsString('stopped reporting', $snap['reason']);
    }

    /**
     * The regression that matters: with a cold cache and an already-dead agent,
     * a naive skew calibration absorbs the staleness into the "clock offset" and
     * reports frozen data as live. Calibration must require proof of life first.
     */
    public function test_dead_agent_on_cold_cache_is_not_mistaken_for_clock_skew(): void
    {
        Cache::flush();
        $this->write(['ts' => microtime(true) - 600]);

        $snap = $this->service()->snapshot();

        $this->assertFalse($snap['available'], 'a 10-minute-old file must never read as fresh');
        $this->assertSame('stale', $snap['status']);
    }

    /**
     * WSL2's clock runs behind the Windows host's, so the agent's timestamps sit
     * in the future from PHP's point of view. That must not read as an error.
     */
    public function test_host_clock_ahead_of_wsl_still_reads_as_fresh(): void
    {
        $this->write(['ts' => microtime(true) + 35]);

        $snap = $this->service()->snapshot();

        $this->assertTrue($snap['available']);
        $this->assertSame(0.0, $snap['age_s']);
    }

    /** Once the agent proves it is alive, the offset is learned and ages normalize. */
    public function test_offset_is_learned_only_after_the_timestamp_advances(): void
    {
        $service = $this->service();

        $this->write(['ts' => microtime(true) + 40]);
        $service->snapshot();
        $this->assertNull($service->snapshot()['clock_offset_s'], 'unchanged ts proves nothing');

        $this->write(['ts' => microtime(true) + 40]);
        $snap = $service->snapshot();

        $this->assertNotNull($snap['clock_offset_s']);
        $this->assertTrue($snap['available']);
    }

    /**
     * Regression: when the host clock is corrected (here, a 35s-fast Windows
     * clock resyncing), the learned offset becomes obsolete in the *upward*
     * direction. A minimum-seeking baseline can never follow it back up, so a
     * perfectly healthy agent reported as "stale" until the cache expired.
     */
    public function test_recovers_when_the_host_clock_is_corrected(): void
    {
        $service = $this->service();

        // Era 1: host clock 35s fast — calibrate against it.
        $this->write(['ts' => microtime(true) + 35]);
        $service->snapshot();
        $this->write(['ts' => microtime(true) + 35]);
        $this->assertTrue($service->snapshot()['available']);
        $this->assertEqualsWithDelta(-35, $service->snapshot()['clock_offset_s'], 2);

        // Era 2: clock corrected. The agent keeps writing, now in true time.
        $this->write(['ts' => microtime(true)]);
        $service->snapshot();
        $this->write(['ts' => microtime(true)]);
        $snap = $service->snapshot();

        $this->assertTrue($snap['available'], 'a live agent must not read as stale after a clock correction');
        $this->assertSame('ok', $snap['status']);
    }

    public function test_low_host_memory_escalates_pressure(): void
    {
        $this->write(['mem' => ['available_mb' => 1500]]);

        $this->assertSame('critical', $this->service()->snapshot()['pressure']);
    }

    public function test_high_commit_escalates_pressure(): void
    {
        $this->write(['commit' => ['pct' => 96.0]]);

        $this->assertSame('critical', $this->service()->snapshot()['pressure']);
    }

    public function test_resources_endpoint_exposes_host_block(): void
    {
        $res = $this->getJson('/api/analytics/resources');

        $res->assertOk()->assertJsonStructure([
            'data' => ['host' => ['available', 'status', 'reason', 'pressure', 'age_s', 'metrics', 'top']],
        ]);
    }
}
