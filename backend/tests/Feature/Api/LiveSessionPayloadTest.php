<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LiveSessionPayloadTest extends TestCase
{
    use RefreshDatabase;

    private string $projectsDir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->projectsDir = sys_get_temp_dir() . '/live-payload-' . uniqid();
        mkdir($this->projectsDir . '/-home-dev-projects-demo', 0777, true);
        config(['services.claude.projects_dir' => $this->projectsDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->projectsDir . '/*/*.jsonl') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->projectsDir . '/-home-dev-projects-demo');
        @rmdir($this->projectsDir);
        parent::tearDown();
    }

    /** Writes a transcript with N assistant text messages; mtime = now. */
    private function writeTranscript(string $sessionId, array $assistantLines, string $cwd = '/home/dev/projects/demo'): void
    {
        $path = $this->projectsDir . "/-home-dev-projects-demo/{$sessionId}.jsonl";
        $lines = [json_encode(['type' => 'user', 'timestamp' => now()->subMinutes(2)->toIso8601String(), 'cwd' => $cwd])];
        foreach ($assistantLines as $i => $text) {
            $lines[] = json_encode([
                'type' => 'assistant',
                'timestamp' => now()->subSeconds(60 - $i)->toIso8601String(),
                'message' => [
                    'model' => 'claude-fable-5',
                    'content' => [['type' => 'text', 'text' => $text]],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ],
            ]);
        }
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    public function test_project_slug_resolved_from_cwd_prefix_match(): void
    {
        Project::factory()->create([
            'slug' => 'demo',
            'name' => 'Demo Project',
            'local_path' => '/home/dev/projects/demo',
            'status' => 'active',
        ]);
        $this->writeTranscript('sess-slug', ['hello']);

        $session = collect($this->getJson('/api/sessions/live')->assertOk()->json('data'))
            ->firstWhere('session_id', 'sess-slug');

        $this->assertSame('demo', $session['project_slug']);
        $this->assertSame('Demo Project', $session['project_name']);
    }

    public function test_project_slug_null_for_unknown_repo(): void
    {
        $this->writeTranscript('sess-unknown', ['hello'], '/somewhere/else');

        $session = collect($this->getJson('/api/sessions/live')->assertOk()->json('data'))
            ->firstWhere('session_id', 'sess-unknown');

        $this->assertNull($session['project_slug']);
    }

    public function test_last_output_lines_keeps_last_five_in_order(): void
    {
        $this->writeTranscript('sess-tail', ['one', 'two', 'three', 'four', 'five', 'six', 'seven']);

        $session = collect($this->getJson('/api/sessions/live')->assertOk()->json('data'))
            ->firstWhere('session_id', 'sess-tail');

        $this->assertSame(['three', 'four', 'five', 'six', 'seven'], $session['last_output_lines']);
        $this->assertSame('seven', $session['last_output_line']);
    }
}
