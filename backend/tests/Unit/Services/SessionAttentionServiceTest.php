<?php

namespace Tests\Unit\Services;

use App\Services\SessionAttentionService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SessionAttentionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_flag_stores_and_classifies_permission_kind(): void
    {
        $service = new SessionAttentionService();
        $service->flag('abc-123', 'Claude needs your permission to use Bash', '/home/dev/projects/x');

        $flags = $service->all();
        $this->assertArrayHasKey('abc-123', $flags);
        $this->assertSame('permission', $flags['abc-123']['kind']);
        $this->assertSame('/home/dev/projects/x', $flags['abc-123']['cwd']);
        $this->assertNotNull($flags['abc-123']['flagged_at']);
    }

    public function test_non_permission_message_classified_as_idle(): void
    {
        $service = new SessionAttentionService();
        $service->flag('abc-123', 'Claude is waiting for your input', null);

        $this->assertSame('idle', $service->all()['abc-123']['kind']);
    }

    public function test_clear_removes_flag(): void
    {
        $service = new SessionAttentionService();
        $service->flag('abc-123', 'x', null);
        $service->clear('abc-123');

        $this->assertSame([], $service->all());
    }
}
