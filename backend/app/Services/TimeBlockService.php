<?php

namespace App\Services;

use App\Models\TimeBlock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TimeBlockService
{
    public function createBlock(array $data): TimeBlock
    {
        return TimeBlock::create($data);
    }

    public function updateBlock(TimeBlock $block, array $data): TimeBlock
    {
        $block->update($data);
        return $block->fresh();
    }

    public function deleteBlock(TimeBlock $block): void
    {
        $block->delete();
    }

    /**
     * Blocks active on the given calendar date, ordered for display.
     */
    public function blocksForDate(string $date): Collection
    {
        $weekday = Carbon::parse($date)->dayOfWeek;
        return TimeBlock::query()
            ->forWeekday($weekday)
            ->ordered()
            ->get();
    }
}
