<?php

namespace Tests\Unit\Services;

use App\Enums\TimeBlockKind;
use App\Models\TimeBlock;
use App\Services\TimeBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeBlockServiceTest extends TestCase
{
    use RefreshDatabase;

    private TimeBlockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TimeBlockService::class);
    }

    public function test_create_block(): void
    {
        $block = $this->service->createBlock([
            'name' => 'Morning',
            'kind' => TimeBlockKind::WORK->value,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'weekday_mask' => 127,
        ]);

        $this->assertNotNull($block->id);
        $this->assertSame('Morning', $block->name);
    }

    public function test_blocks_for_date_returns_only_active_weekday(): void
    {
        // Saturday 2026-04-18 → weekday 6 → bit 64
        TimeBlock::factory()->create(['name' => 'WeekendOnly', 'weekday_mask' => 64]);
        TimeBlock::factory()->create(['name' => 'WeekdaysOnly', 'weekday_mask' => 62]);
        TimeBlock::factory()->create(['name' => 'AllDays', 'weekday_mask' => 127]);

        $names = $this->service->blocksForDate('2026-04-18')->pluck('name')->all();

        $this->assertContains('WeekendOnly', $names);
        $this->assertContains('AllDays', $names);
        $this->assertNotContains('WeekdaysOnly', $names);
    }

    public function test_update_block(): void
    {
        $block = TimeBlock::factory()->create(['name' => 'Old']);

        $updated = $this->service->updateBlock($block, ['name' => 'New']);

        $this->assertSame('New', $updated->name);
    }

    public function test_delete_block(): void
    {
        $block = TimeBlock::factory()->create();

        $this->service->deleteBlock($block);

        $this->assertDatabaseMissing('time_blocks', ['id' => $block->id]);
    }
}
