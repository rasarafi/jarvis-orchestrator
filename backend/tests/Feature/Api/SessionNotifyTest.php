<?php

namespace Tests\Feature\Api;

use App\Services\SessionAttentionService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SessionNotifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_notify_stores_attention_flag(): void
    {
        $this->postJson('/api/sessions/notify', [
            'session_id' => 'sess-1',
            'message' => 'Claude needs your permission to use Bash',
            'cwd' => '/home/dev/projects/jarvis-v2',
            'hook_event_name' => 'Notification',
            'transcript_path' => '/ignored/extra/field.jsonl',
        ])->assertOk()->assertJson(['ok' => true]);

        $flags = app(SessionAttentionService::class)->all();
        $this->assertSame('permission', $flags['sess-1']['kind']);
        $this->assertSame('/home/dev/projects/jarvis-v2', $flags['sess-1']['cwd']);
    }

    public function test_notify_requires_session_id(): void
    {
        $this->postJson('/api/sessions/notify', ['message' => 'hi'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['session_id']);
    }
}
