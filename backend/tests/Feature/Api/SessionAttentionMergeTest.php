<?php

namespace Tests\Feature\Api;

use App\Services\SessionAttentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SessionAttentionMergeTest extends TestCase
{
    use RefreshDatabase;

    private string $projectsDir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->projectsDir = sys_get_temp_dir() . '/live-attn-' . uniqid();
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

    /** Writes a two-message transcript whose file mtime is $idleSeconds ago. */
    private function writeTranscript(string $sessionId, int $idleSeconds): void
    {
        $path = $this->projectsDir . "/-home-dev-projects-demo/{$sessionId}.jsonl";
        $lines = [
            json_encode([
                'type' => 'user',
                'timestamp' => now()->subSeconds($idleSeconds + 60)->toIso8601String(),
                'cwd' => '/home/dev/projects/demo',
            ]),
            json_encode([
                'type' => 'assistant',
                'timestamp' => now()->subSeconds($idleSeconds)->toIso8601String(),
                'message' => [
                    'model' => 'claude-fable-5',
                    'content' => [['type' => 'text', 'text' => 'done — your move']],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ]),
        ];
        file_put_contents($path, implode("\n", $lines) . "\n");
        touch($path, time() - $idleSeconds);
    }

    public function test_attention_flag_is_merged_into_waiting_session(): void
    {
        $this->writeTranscript('sess-attn', 300); // idle 5 min => waiting
        app(SessionAttentionService::class)->flag('sess-attn', 'Claude is waiting for your input', '/home/dev/projects/demo');

        $response = $this->getJson('/api/sessions/live')->assertOk();
        $session = collect($response->json('data'))->firstWhere('session_id', 'sess-attn');

        $this->assertNotNull($session);
        $this->assertSame('waiting', $session['status']);
        $this->assertSame('idle', $session['attention']['kind']);
        $this->assertSame('Claude is waiting for your input', $session['attention']['message']);
    }

    public function test_flag_cleared_when_session_is_working_again(): void
    {
        $this->writeTranscript('sess-work', 5); // idle 5s => working
        app(SessionAttentionService::class)->flag('sess-work', 'Claude needs your permission to use Bash', null);

        $response = $this->getJson('/api/sessions/live')->assertOk();
        $session = collect($response->json('data'))->firstWhere('session_id', 'sess-work');

        $this->assertNotNull($session);
        $this->assertSame('working', $session['status']);
        $this->assertNull($session['attention']);
        $this->assertSame([], app(SessionAttentionService::class)->all());
    }

    public function test_sessions_without_flags_have_null_attention(): void
    {
        $this->writeTranscript('sess-plain', 300);

        $response = $this->getJson('/api/sessions/live')->assertOk();
        $session = collect($response->json('data'))->firstWhere('session_id', 'sess-plain');

        $this->assertNotNull($session);
        $this->assertNull($session['attention']);
    }

    public function test_flag_cleared_when_activity_is_fresher_than_flag_plus_grace(): void
    {
        // idle 300s => waiting; the transcript's last_activity_at is "now - 300s".
        $this->writeTranscript('sess-fresh', 300);

        // Flag it as if it happened well before that activity (so last_activity_at
        // is newer than flagged_at + 10s grace) — simulates the human having
        // already responded after the flag was raised, even though the poll
        // never caught the session mid-WORKING.
        app(SessionAttentionService::class)->flag('sess-fresh', 'Claude is waiting for your input', null);
        $flags = app(SessionAttentionService::class)->all();
        $flags['sess-fresh']['flagged_at'] = now()->subSeconds(600)->toIso8601String();
        Cache::put('session_attention_flags', $flags, now()->addMinutes(240));

        $response = $this->getJson('/api/sessions/live')->assertOk();
        $session = collect($response->json('data'))->firstWhere('session_id', 'sess-fresh');

        $this->assertNotNull($session);
        $this->assertSame('waiting', $session['status']);
        $this->assertNull($session['attention']);
        $this->assertArrayNotHasKey('sess-fresh', app(SessionAttentionService::class)->all());
    }

    public function test_prune_absent_removes_flags_older_than_the_live_window(): void
    {
        // One real live session so the payload's live-session-ids list is non-empty.
        $this->writeTranscript('sess-live', 300);

        app(SessionAttentionService::class)->flag('sess-gone-old', 'Claude needs your permission', null);
        $flags = app(SessionAttentionService::class)->all();
        $flags['sess-gone-old']['flagged_at'] = now()->subMinutes(31)->toIso8601String();
        Cache::put('session_attention_flags', $flags, now()->addMinutes(240));

        app(SessionAttentionService::class)->flag('sess-gone-recent', 'Claude needs your permission', null);
        $flags = app(SessionAttentionService::class)->all();
        $flags['sess-gone-recent']['flagged_at'] = now()->subMinutes(5)->toIso8601String();
        Cache::put('session_attention_flags', $flags, now()->addMinutes(240));

        $this->getJson('/api/sessions/live')->assertOk();

        $remaining = app(SessionAttentionService::class)->all();
        $this->assertArrayNotHasKey('sess-gone-old', $remaining);
        $this->assertArrayHasKey('sess-gone-recent', $remaining);
    }
}
