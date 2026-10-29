<?php // backend/tests/Feature/Models/SessionRecapTest.php
namespace Tests\Feature\Models;

use App\Enums\RecapKind;
use App\Enums\RecapStatus;
use App\Enums\RecapThreadStatus;
use App\Models\RecapThread;
use App\Models\SessionRecap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionRecapTest extends TestCase
{
    use RefreshDatabase;

    public function test_recap_persists_with_casts_and_threads(): void
    {
        $startedAt = now()->subHour();
        $endedAt = now();
        $summarizedAt = now();

        $recap = SessionRecap::create([
            'cc_session_id' => 'abc-123',
            'kind' => RecapKind::WORK,
            'status' => RecapStatus::OK,
            'model' => 'haiku',
            'headline' => 'Invoice export groundwork',
            'bullets' => ['Built CSV export', 'Wired admin route'],
            'decisions' => ['Use raw CSV format', 'Skip VAT on first pass'],
            'facts' => ['messages' => 39, 'duration_minutes' => 95],
            'duration_minutes' => 95,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'summarized_at' => $summarizedAt,
        ]);

        $thread = RecapThread::create([
            'source_recap_id' => $recap->id,
            'text' => 'Blocked on missing VAT column',
            'status' => RecapThreadStatus::OPEN,
        ]);

        $fresh = SessionRecap::with('threads')->find($recap->id);

        // Test enum casts
        $this->assertSame(RecapKind::WORK, $fresh->kind);
        $this->assertSame(RecapStatus::OK, $fresh->status);
        $this->assertSame(RecapThreadStatus::OPEN, $fresh->threads->first()->status);

        // Test array casts (decisions and facts round-trip)
        $this->assertSame(['Use raw CSV format', 'Skip VAT on first pass'], $fresh->decisions);
        $this->assertSame(['messages' => 39, 'duration_minutes' => 95], $fresh->facts);
        $this->assertSame(['Built CSV export', 'Wired admin route'], $fresh->bullets);

        // Test datetime casts
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->started_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->ended_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->summarized_at);

        // Test relations
        $this->assertCount(1, $fresh->threads);
        $this->assertNull($fresh->project);
        $this->assertTrue(RecapThread::open()->whereKey($thread->id)->exists());
    }
}
