<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TodaySessionsTest extends TestCase
{
    use RefreshDatabase;

    private function seedCcSession(string $decodedPath, string $start, string $end, float $cost): void
    {
        $ccProjectId = DB::table('sessions.projects')->insertGetId([
            'encoded_path' => str_replace('/', '-', $decodedPath),
            'decoded_path' => $decodedPath,
            'display_name' => basename($decodedPath),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sessions.sessions')->insert([
            'session_id' => 'sess-' . uniqid(),
            'project_id' => $ccProjectId,
            'file_path' => '/tmp/' . uniqid() . '.jsonl',
            'file_size' => 1024,
            'file_mtime' => now()->getTimestampMs(),
            'first_prompt_timestamp' => $start,
            'last_activity_timestamp' => $end,
            'message_count' => 10,
            'estimated_cost_usd' => $cost,
            'indexed_at' => now(),
        ]);
    }

    public function test_returns_today_spans_with_project_slug(): void
    {
        Project::factory()->create([
            'slug' => 'demo',
            'name' => 'Demo',
            'local_path' => '/home/dev/projects/demo',
        ]);
        $this->seedCcSession(
            '/home/dev/projects/demo',
            now()->setTime(9, 0)->toDateTimeString(),
            now()->setTime(10, 30)->toDateTimeString(),
            1.25,
        );

        $response = $this->getJson('/api/analytics/today-sessions')->assertOk();
        $span = $response->json('data.0');

        $this->assertSame('demo', $span['project_slug']);
        $this->assertSame(5400, $span['seconds']);
        $this->assertEqualsWithDelta(1.25, $span['cost_usd'], 0.001);
    }

    public function test_unknown_project_has_null_slug_and_display_name(): void
    {
        $this->seedCcSession(
            '/somewhere/unmapped',
            now()->setTime(11, 0)->toDateTimeString(),
            now()->setTime(11, 20)->toDateTimeString(),
            0.10,
        );

        $span = $this->getJson('/api/analytics/today-sessions')->assertOk()->json('data.0');

        $this->assertNull($span['project_slug']);
        $this->assertSame('unmapped', $span['project_name']);
    }

    public function test_other_days_excluded_and_date_param_works(): void
    {
        $this->seedCcSession(
            '/somewhere/yesterday',
            now()->subDay()->setTime(9, 0)->toDateTimeString(),
            now()->subDay()->setTime(9, 30)->toDateTimeString(),
            0.5,
        );

        $this->assertCount(0, $this->getJson('/api/analytics/today-sessions')->json('data'));
        $this->assertCount(1, $this->getJson('/api/analytics/today-sessions?date=' . now()->subDay()->toDateString())->json('data'));
    }
}
