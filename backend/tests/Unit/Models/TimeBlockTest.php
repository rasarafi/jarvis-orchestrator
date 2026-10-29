<?php

namespace Tests\Unit\Models;

use App\Enums\TimeBlockKind;
use App\Models\TimeBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_via_factory(): void
    {
        $block = TimeBlock::factory()->create();

        $this->assertNotNull($block->id);
        $this->assertSame(TimeBlockKind::WORK, $block->kind);
        $this->assertSame(127, $block->weekday_mask);
    }

    public function test_personal_factory_state(): void
    {
        $block = TimeBlock::factory()->personal()->create();

        $this->assertSame(TimeBlockKind::PERSONAL, $block->kind);
    }

    public function test_for_weekday_filters_by_mask(): void
    {
        // Mon-only block: bit for Monday = 1 << 1 = 2
        TimeBlock::factory()->create(['name' => 'Mon', 'weekday_mask' => 2]);
        // Sun-only: bit = 1
        TimeBlock::factory()->create(['name' => 'Sun', 'weekday_mask' => 1]);

        $monBlocks = TimeBlock::forWeekday(1)->get();
        $sunBlocks = TimeBlock::forWeekday(0)->get();

        $this->assertCount(1, $monBlocks);
        $this->assertSame('Mon', $monBlocks->first()->name);
        $this->assertCount(1, $sunBlocks);
        $this->assertSame('Sun', $sunBlocks->first()->name);
    }

    public function test_ordered_sorts_by_display_order_then_start(): void
    {
        TimeBlock::factory()->create(['name' => 'B', 'display_order' => 1, 'start_time' => '09:00:00']);
        TimeBlock::factory()->create(['name' => 'A', 'display_order' => 0, 'start_time' => '14:00:00']);
        TimeBlock::factory()->create(['name' => 'C', 'display_order' => 1, 'start_time' => '08:00:00']);

        $ordered = TimeBlock::ordered()->get()->pluck('name')->all();

        $this->assertSame(['A', 'C', 'B'], $ordered);
    }
}
