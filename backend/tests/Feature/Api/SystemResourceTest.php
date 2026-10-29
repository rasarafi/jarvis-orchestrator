<?php // backend/tests/Feature/Api/SystemResourceTest.php
namespace Tests\Feature\Api;

use App\Enums\ResourcePressure;
use App\Models\ResourceSample;
use App\Services\SystemResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_endpoint_returns_vitals_and_verdict(): void
    {
        $res = $this->getJson('/api/analytics/resources');

        $res->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'pressure', 'message', 'detail',
                    'metrics' => [
                        'cpu_load', 'cores', 'load_per_core',
                        'mem_used_mb', 'mem_total_mb', 'mem_available_mb', 'mem_pct',
                        'swap_used_mb', 'swap_total_mb', 'swap_pct', 'session_count',
                    ],
                    'sessions', 'sampled_at',
                ],
            ]);

        $pressure = $res->json('data.pressure');
        $this->assertContains($pressure, array_column(ResourcePressure::cases(), 'value'));
        // A real Linux host reports a non-zero total memory ceiling.
        $this->assertGreaterThan(0, $res->json('data.metrics.mem_total_mb'));
    }

    public function test_trend_endpoint_returns_samples_within_window(): void
    {
        ResourceSample::create($this->sampleRow(sampledAt: now()->subMinutes(30)));
        ResourceSample::create($this->sampleRow(sampledAt: now()->subMinutes(5)));
        // Outside the default 120-minute window — must be excluded.
        ResourceSample::create($this->sampleRow(sampledAt: now()->subHours(4)));

        $res = $this->getJson('/api/analytics/resources/trend?window=120');

        $res->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['sampled_at', 'cpu_load', 'cores', 'mem_pct', 'swap_pct', 'pressure']],
                'meta' => ['window'],
            ]);
    }

    public function test_sample_persists_a_row(): void
    {
        $sample = app(SystemResourceService::class)->sample();

        $this->assertDatabaseHas('resource_samples', ['id' => $sample->id]);
        $this->assertGreaterThan(0, $sample->mem_total_mb);
    }

    public function test_prune_drops_only_old_samples(): void
    {
        ResourceSample::create($this->sampleRow(sampledAt: now()->subDays(10)));
        $keep = ResourceSample::create($this->sampleRow(sampledAt: now()->subDays(1)));

        $deleted = app(SystemResourceService::class)->prune(7);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseHas('resource_samples', ['id' => $keep->id]);
    }

    private function sampleRow(\DateTimeInterface $sampledAt): array
    {
        return [
            'cpu_load' => 2.5, 'cores' => 8,
            'mem_used_mb' => 8000, 'mem_total_mb' => 16000,
            'swap_used_mb' => 200, 'swap_total_mb' => 4000,
            'session_count' => 3, 'pressure' => ResourcePressure::OK->value,
            'sampled_at' => $sampledAt,
        ];
    }
}
