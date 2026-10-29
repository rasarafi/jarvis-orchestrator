<?php

namespace Tests\Unit\Models;

use App\Enums\FocusSessionStatus;
use App\Models\FocusSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FocusSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_via_factory(): void
    {
        $session = FocusSession::factory()->create();

        $this->assertSame(FocusSessionStatus::ACTIVE, $session->status);
        $this->assertNotNull($session->task);
    }

    public function test_open_scope_excludes_ended(): void
    {
        FocusSession::factory()->create();        // active
        FocusSession::factory()->ended()->create();

        $this->assertCount(1, FocusSession::open()->get());
    }

    public function test_ended_factory_state(): void
    {
        $session = FocusSession::factory()->ended()->create();

        $this->assertSame(FocusSessionStatus::ENDED, $session->status);
        $this->assertNotNull($session->ended_at);
        $this->assertSame(1500, $session->elapsed_seconds);
    }
}
