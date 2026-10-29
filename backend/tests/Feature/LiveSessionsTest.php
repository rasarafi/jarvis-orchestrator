<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LiveSessionsTest extends TestCase
{
    use RefreshDatabase;

    private string $projectsDir;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->projectsDir = sys_get_temp_dir() . '/live-sessions-test-' . uniqid();
        mkdir($this->projectsDir . '/-home-dev-projects-acme', 0777, true);
        mkdir($this->projectsDir . '/-home-dev-projects-stale', 0777, true);
        config(['services.claude.projects_dir' => $this->projectsDir]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectsDir));
        parent::tearDown();
    }

    private function writeSession(string $dir, string $uuid, array $lines, int $mtimeAgoSeconds): string
    {
        $path = "{$this->projectsDir}/{$dir}/{$uuid}.jsonl";
        file_put_contents($path, implode("\n", array_map(fn ($l) => json_encode($l), $lines)) . "\n");
        touch($path, time() - $mtimeAgoSeconds);

        return $path;
    }

    private function userLine(string $cwd, string $ts, string $text = 'do the thing'): array
    {
        return [
            'type' => 'user',
            'timestamp' => $ts,
            'cwd' => $cwd,
            'gitBranch' => 'main',
            'sessionId' => 'abc',
            'message' => ['role' => 'user', 'content' => $text],
        ];
    }

    private function assistantLine(string $cwd, string $ts, string $text, array $usage): array
    {
        return [
            'type' => 'assistant',
            'timestamp' => $ts,
            'cwd' => $cwd,
            'gitBranch' => 'main',
            'sessionId' => 'abc',
            'message' => [
                'role' => 'assistant',
                'model' => 'claude-fable-5',
                'content' => [['type' => 'text', 'text' => $text]],
                'usage' => $usage,
            ],
        ];
    }

    public function test_live_returns_recent_session_with_tokens_cost_and_status(): void
    {
        Project::create([
            'slug' => 'acme',
            'name' => 'Acme App',
            'purpose' => 'test fixture',
            'stack' => ['php'],
            'local_path' => '/home/dev/projects/acme',
            'status' => 'active',
        ]);

        $cwd = '/home/dev/projects/acme';
        $now = now();

        // Fresh session: last line is assistant, mtime 120s ago -> "waiting"
        $this->writeSession('-home-dev-projects-acme', 'aaaaaaaa-1111', [
            $this->userLine($cwd, $now->copy()->subMinutes(10)->toIso8601String()),
            $this->assistantLine($cwd, $now->copy()->subMinutes(2)->toIso8601String(), 'Done, waiting for your call.', [
                'input_tokens' => 100,
                'output_tokens' => 50,
                'cache_creation_input_tokens' => 200,
                'cache_read_input_tokens' => 1000,
            ]),
        ], 120);

        // Stale session: mtime 2h ago -> excluded
        $this->writeSession('-home-dev-projects-stale', 'bbbbbbbb-2222', [
            $this->userLine('/home/dev/projects/stale', $now->copy()->subHours(3)->toIso8601String()),
        ], 7200);

        $response = $this->getJson('/api/sessions/live');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');

        $session = $response->json('data.0');
        $this->assertSame('waiting', $session['status']);
        $this->assertSame('Acme App', $session['project_name']);
        $this->assertSame($cwd, $session['cwd']);
        $this->assertSame('main', $session['git_branch']);
        $this->assertSame('claude-fable-5', $session['model']);
        $this->assertSame('Done, waiting for your call.', $session['last_output_line']);
        $this->assertSame(100, $session['tokens']['input']);
        $this->assertSame(50, $session['tokens']['output']);
        $this->assertSame(200, $session['tokens']['cache_creation']);
        $this->assertSame(1000, $session['tokens']['cache_read']);
        // fable-5: in $10, out $50, cache write 1.25x, cache read 0.1x per MTok
        // 100*10 + 50*50 + 200*12.5 + 1000*1.0 = 1000+2500+2500+1000 = 7000 micro-dollars
        $this->assertEqualsWithDelta(0.007, $session['cost_usd'], 0.0001);
        $this->assertNotNull($session['started_at']);
        $this->assertNotNull($session['last_activity_at']);
    }

    public function test_working_status_for_freshly_modified_file(): void
    {
        // A project with an empty local_path must never prefix-match every cwd.
        Project::create([
            'slug' => 'pathless',
            'name' => 'Pathless Project',
            'purpose' => 'test fixture',
            'stack' => ['php'],
            'local_path' => '',
            'status' => 'active',
        ]);

        $cwd = '/home/dev/projects/acme';
        $this->writeSession('-home-dev-projects-acme', 'cccccccc-3333', [
            $this->userLine($cwd, now()->subSeconds(30)->toIso8601String()),
        ], 5);

        $session = $this->getJson('/api/sessions/live')->assertOk()->json('data.0');
        $this->assertSame('working', $session['status']);
        // No project row matches -> falls back to cwd basename
        $this->assertSame('acme', $session['project_name']);
    }

    public function test_fresh_mtime_with_stale_message_timestamps_is_excluded(): void
    {
        // A copied/restored transcript: mtime is now, but the newest message
        // inside is hours old. Must not show as live.
        $this->writeSession('-home-dev-projects-acme', 'eeeeeeee-5555', [
            $this->userLine('/home/dev/projects/acme', now()->subHours(5)->toIso8601String()),
            $this->assistantLine('/home/dev/projects/acme', now()->subHours(4)->toIso8601String(), 'old work', [
                'input_tokens' => 10, 'output_tokens' => 10,
                'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0,
            ]),
        ], 0);

        $this->getJson('/api/sessions/live')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_status_derives_from_message_timestamp_not_mtime(): void
    {
        // Last message 5 minutes ago but file touched just now (e.g. copied):
        // must be "waiting", not "working".
        $this->writeSession('-home-dev-projects-acme', 'ffffffff-6666', [
            $this->assistantLine('/home/dev/projects/acme', now()->subMinutes(5)->toIso8601String(), 'done', [
                'input_tokens' => 10, 'output_tokens' => 10,
                'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0,
            ]),
        ], 0);

        $session = $this->getJson('/api/sessions/live')->assertOk()->json('data.0');
        $this->assertSame('waiting', $session['status']);
    }

    public function test_incremental_parse_picks_up_appended_lines(): void
    {
        $cwd = '/home/dev/projects/acme';
        $path = $this->writeSession('-home-dev-projects-acme', 'dddddddd-4444', [
            $this->assistantLine($cwd, now()->subMinutes(3)->toIso8601String(), 'first', [
                'input_tokens' => 10, 'output_tokens' => 10,
                'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0,
            ]),
        ], 5);

        $first = $this->getJson('/api/sessions/live')->json('data.0');
        $this->assertSame(10, $first['tokens']['output']);

        // Append another assistant line; totals must accumulate, not reset
        file_put_contents($path, json_encode($this->assistantLine($cwd, now()->toIso8601String(), 'second', [
            'input_tokens' => 5, 'output_tokens' => 20,
            'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0,
        ])) . "\n", FILE_APPEND);
        touch($path, time());

        $second = $this->getJson('/api/sessions/live')->json('data.0');
        $this->assertSame(30, $second['tokens']['output']);
        $this->assertSame('second', $second['last_output_line']);
    }
}
