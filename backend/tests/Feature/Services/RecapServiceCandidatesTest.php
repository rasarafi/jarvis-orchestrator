<?php // backend/tests/Feature/Services/RecapServiceCandidatesTest.php
namespace Tests\Feature\Services;

use App\Enums\RecapKind;
use App\Enums\RecapStatus;
use App\Models\Project;
use App\Models\SessionRecap;
use App\Services\RecapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecapServiceCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private ?int $ccProjectId = null;

    private function ccProjectId(): int
    {
        if ($this->ccProjectId !== null) {
            return $this->ccProjectId;
        }

        return $this->ccProjectId = DB::table('sessions.projects')->insertGetId([
            'encoded_path' => '-home-dev-projects-jarvis-v2',
            'decoded_path' => '/home/dev/projects/jarvis-v2',
            'display_name' => 'jarvis-v2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedCcSession(array $overrides = []): string
    {
        static $n = 0;
        $sessionId = $overrides['session_id'] ?? 'sess-'.(++$n);
        DB::table('sessions.sessions')->insert(array_merge([
            'session_id' => $sessionId,
            'project_id' => $this->ccProjectId(),
            'file_path' => "/tmp/{$sessionId}.jsonl",
            'file_size' => 100,
            'file_mtime' => now()->timestamp,
            'first_prompt' => 'work on the thing',
            'first_prompt_timestamp' => now()->subHours(3),
            'last_activity_timestamp' => now()->subHours(2),
            'message_count' => 40,
            'turn_count' => 12,
            'indexed_at' => now(),
        ], $overrides));

        return $sessionId;
    }

    public function test_candidates_excludes_processed_marker_and_live_sessions(): void
    {
        $failed = $this->seedCcSession(['session_id' => 'sess-failed', 'last_activity_timestamp' => now()->subHours(5)]);
        SessionRecap::create(['cc_session_id' => $failed, 'kind' => RecapKind::WORK, 'status' => RecapStatus::FAILED]);
        $wanted = $this->seedCcSession();
        $this->seedCcSession(['session_id' => 'sess-nullprompt', 'first_prompt' => null, 'last_activity_timestamp' => now()->subHours(1)]);
        $this->seedCcSession(['session_id' => 'sess-live', 'last_activity_timestamp' => now()->subMinutes(5)]);
        $this->seedCcSession(['session_id' => 'sess-self', 'first_prompt' => RecapService::PROMPT_MARKER.' summarize...']);
        $done = $this->seedCcSession(['session_id' => 'sess-done']);
        SessionRecap::create(['cc_session_id' => $done, 'kind' => RecapKind::WORK]);
        $this->seedCcSession(['session_id' => 'sess-old', 'last_activity_timestamp' => now()->subDays(9)]);

        $ids = app(RecapService::class)->candidates(7)->pluck('session_id')->all();

        $this->assertSame([$wanted, 'sess-nullprompt'], $ids);
    }

    public function test_classify_uses_message_count_and_duration(): void
    {
        // classify() now derives duration from activeMinutes() (message-gap based), not the raw
        // first-to-last span. A session with no session_id / no seeded sessions.messages rows
        // falls into activeMinutes()'s <2-timestamps fallback, which is `min(durationMinutes, 5)` —
        // i.e. never enough on its own to cross the 20-minute WORK threshold. So "long wall-clock
        // span, few messages" (the old $longQuiet fixture) can no longer demonstrate the
        // duration-only WORK path without real messages: a long *idle* span is exactly the
        // duration-inflation bug this fix closes, so it's correct for it to classify as CHORE now.
        // To keep exercising the "duration alone can cross the threshold" branch, $activeLong seeds
        // real sessions.messages with active (non-idle) gaps summing to >= 20 minutes.
        $service = app(RecapService::class);
        $chore = (object) ['session_id' => 'classify-chore', 'message_count' => 5, 'first_prompt_timestamp' => now()->subMinutes(10), 'last_activity_timestamp' => now()->subMinutes(4)];
        $longIdle = (object) ['session_id' => 'classify-long-idle', 'message_count' => 5, 'first_prompt_timestamp' => now()->subMinutes(90), 'last_activity_timestamp' => now()->subMinutes(5)];
        $busy = (object) ['session_id' => 'classify-busy', 'message_count' => 30, 'first_prompt_timestamp' => now()->subMinutes(10), 'last_activity_timestamp' => now()->subMinutes(4)];

        $activeLongId = $this->seedCcSession([
            'session_id' => 'classify-active-long',
            'first_prompt_timestamp' => now()->subMinutes(25),
            'last_activity_timestamp' => now()->subMinutes(3),
            'message_count' => 5,
        ]);
        DB::table('sessions.messages')->insert([
            ['session_id' => $activeLongId, 'message_uuid' => $activeLongId.'-m1', 'message_type' => 'user', 'timestamp' => now()->subMinutes(25), 'content_text' => 'start', 'is_sidechain' => false, 'sequence_number' => 1],
            ['session_id' => $activeLongId, 'message_uuid' => $activeLongId.'-m2', 'message_type' => 'assistant', 'timestamp' => now()->subMinutes(15), 'content_text' => 'working', 'is_sidechain' => false, 'sequence_number' => 2],
            ['session_id' => $activeLongId, 'message_uuid' => $activeLongId.'-m3', 'message_type' => 'assistant', 'timestamp' => now()->subMinutes(3), 'content_text' => 'done', 'is_sidechain' => false, 'sequence_number' => 3],
        ]);
        $activeLong = DB::table('sessions.sessions')->where('session_id', $activeLongId)->first();

        $this->assertSame(RecapKind::CHORE, $service->classify($chore));
        $this->assertSame(RecapKind::CHORE, $service->classify($longIdle), 'a long wall-clock span with no real message activity is the duration-inflation bug — must not classify as WORK');
        $this->assertSame(RecapKind::WORK, $service->classify($busy));
        $this->assertSame(RecapKind::WORK, $service->classify($activeLong), 'active message gaps summing to >= 20 minutes should still classify as WORK via the duration branch');
    }

    public function test_store_chore_persists_row_with_project_resolution(): void
    {
        $project = Project::factory()->create(['local_path' => '/home/dev/projects/jarvis-v2']);
        Project::factory()->create(['local_path' => '/home/dev/projects']);
        $sessionId = $this->seedCcSession([
            'first_prompt' => 'quick git push please',
            'cwd' => '/home/dev/projects/jarvis-v2/backend',
            'message_count' => 4,
        ]);
        $session = DB::table('sessions.sessions')->where('session_id', $sessionId)->first();

        $recap = app(RecapService::class)->storeChore($session);

        $this->assertSame(RecapKind::CHORE, $recap->kind);
        $this->assertSame($project->id, $recap->project_id);
        $this->assertSame('quick git push please', $recap->headline);
        $this->assertSame(4, $recap->facts['messages']);
    }

    public function test_active_minutes_caps_resumed_session_gaps(): void
    {
        // Regression for the duration-inflation defect: a session resumed 3 days later must not
        // count the multi-day idle gap at face value. Messages at t0, t0+5min, t0+8min, t0+3days
        // should yield active minutes of roughly 5 + 3 + 15 (the 3-day gap capped at the 15-minute
        // per-gap cap) = 23, not the ~4,328-minute raw span.
        $sessionId = 'sess-resumed';
        $t0 = now()->subDays(3);
        $this->seedCcSession([
            'session_id' => $sessionId,
            'first_prompt' => 'start work',
            'first_prompt_timestamp' => $t0->copy(),
            'last_activity_timestamp' => $t0->copy()->addDays(3),
            'message_count' => 4,
        ]);
        DB::table('sessions.messages')->insert([
            ['session_id' => $sessionId, 'message_uuid' => $sessionId.'-m1', 'message_type' => 'user', 'timestamp' => $t0->copy(), 'content_text' => 'start work', 'is_sidechain' => false, 'sequence_number' => 1],
            ['session_id' => $sessionId, 'message_uuid' => $sessionId.'-m2', 'message_type' => 'assistant', 'timestamp' => $t0->copy()->addMinutes(5), 'content_text' => 'progress', 'is_sidechain' => false, 'sequence_number' => 2],
            ['session_id' => $sessionId, 'message_uuid' => $sessionId.'-m3', 'message_type' => 'assistant', 'timestamp' => $t0->copy()->addMinutes(8), 'content_text' => 'pausing here', 'is_sidechain' => false, 'sequence_number' => 3],
            ['session_id' => $sessionId, 'message_uuid' => $sessionId.'-m4', 'message_type' => 'user', 'timestamp' => $t0->copy()->addDays(3), 'content_text' => 'resuming after 3 days', 'is_sidechain' => false, 'sequence_number' => 4],
        ]);
        $session = DB::table('sessions.sessions')->where('session_id', $sessionId)->first();
        $service = app(RecapService::class);

        $active = $service->activeMinutes($session);
        $this->assertSame(23, $active);

        // The raw span (durationMinutes) is what the bug used to store — confirm it's wildly larger
        // so the assertion above is meaningfully different from the old behavior.
        $this->assertGreaterThan(4000, $service->durationMinutes($session));

        $recap = $service->storeChore($session);

        $this->assertSame(23, $recap->duration_minutes, 'stored duration_minutes must use active time, not the multi-day raw span');
        $this->assertSame(23, $recap->facts['duration_minutes']);
    }
}
