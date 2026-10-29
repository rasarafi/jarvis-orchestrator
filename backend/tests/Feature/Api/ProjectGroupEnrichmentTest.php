<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CC-session payloads (recent sessions, weekly cost breakdown) are enriched
 * with the matching Jarvis project's group via decoded_path <-> local_path.
 */
class ProjectGroupEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private function seedCcSession(string $decodedPath, string $displayName): void
    {
        $ccProjectId = DB::table('sessions.projects')->insertGetId([
            'encoded_path' => str_replace('/', '-', $decodedPath),
            'decoded_path' => $decodedPath,
            'display_name' => $displayName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sessions.sessions')->insert([
            'session_id' => 'sess-' . uniqid(),
            'project_id' => $ccProjectId,
            'file_path' => '/tmp/' . uniqid() . '.jsonl',
            'file_size' => 1024,
            'file_mtime' => now()->getTimestampMs(),
            'first_prompt_timestamp' => now()->subHour(),
            'last_activity_timestamp' => now(),
            'message_count' => 10,
            'estimated_cost_usd' => 2.5,
            'total_input_tokens' => 1000,
            'total_output_tokens' => 500,
            'indexed_at' => now(),
        ]);
    }

    public function test_recent_sessions_include_jarvis_project_group(): void
    {
        Project::factory()->create([
            'slug' => 'demo',
            'local_path' => '/home/dev/projects/demo',
            'group_key' => 'fa',
        ]);
        $this->seedCcSession('/home/dev/projects/demo', 'Demo');
        $this->seedCcSession('/home/dev/projects/unknown', 'Unknown');

        $rows = $this->getJson('/api/sessions/recent')->assertOk()->json('data');

        $byName = collect($rows)->keyBy(fn ($r) => $r['cc_project']['display_name']);
        $this->assertSame('fa', $byName['Demo']['jarvis_project']['group_key']);
        $this->assertSame('demo', $byName['Demo']['jarvis_project']['slug']);
        $this->assertNull($byName['Unknown']['jarvis_project']);
    }

    public function test_weekly_breakdown_includes_group_key(): void
    {
        Project::factory()->create([
            'slug' => 'demo',
            'local_path' => '/home/dev/projects/demo',
            'group_key' => 'mfb',
        ]);
        $this->seedCcSession('/home/dev/projects/demo', 'Demo');
        $this->seedCcSession('/home/dev/projects/unknown', 'Unknown');

        $rows = $this->getJson('/api/analytics/sessions')->assertOk()->json('data');

        $byName = collect($rows)->keyBy('display_name');
        $this->assertSame('mfb', $byName['Demo']['group_key']);
        $this->assertNull($byName['Unknown']['group_key']);
    }

    public function test_weekly_breakdown_merges_same_display_name(): void
    {
        // Two CC projects sharing a display_name (different paths) must stay one row.
        $this->seedCcSession('/home/dev/projects/a/scratch', 'scratch');
        $this->seedCcSession('/home/dev/projects/b/scratch', 'scratch');

        $rows = $this->getJson('/api/analytics/sessions')->assertOk()->json('data');

        $scratch = collect($rows)->where('display_name', 'scratch');
        $this->assertCount(1, $scratch);
        $this->assertSame(2, $scratch->first()['sessions']);
        $this->assertEqualsWithDelta(5.0, (float) $scratch->first()['cost'], 0.001);
    }
}
