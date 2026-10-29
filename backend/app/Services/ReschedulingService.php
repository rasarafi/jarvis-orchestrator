<?php

namespace App\Services;

use App\Enums\RescheduleAction;
use App\Models\ScheduledTask;
use App\Models\TimeBlock;
use Carbon\Carbon;
use RuntimeException;

class ReschedulingService
{
    /**
     * Apply a reschedule action to a scheduled task.
     * Returns the (possibly null) resulting record. Returns null when the
     * action removed the schedule (skip / to_backlog).
     */
    public function reschedule(ScheduledTask $st, RescheduleAction $action, array $payload = []): ?ScheduledTask
    {
        return match ($action) {
            RescheduleAction::PUSH_1HR => $this->push1hr($st),
            RescheduleAction::PUSH_EVENING => $this->pushToEvening($st),
            RescheduleAction::PUSH_TOMORROW => $this->pushToTomorrow($st),
            RescheduleAction::SKIP, RescheduleAction::TO_BACKLOG => $this->remove($st),
            RescheduleAction::TO_TIME => $this->setTime($st, $payload),
        };
    }

    private function push1hr(ScheduledTask $st): ScheduledTask
    {
        $st->update([
            'start_time' => Carbon::parse($st->start_time)->addHour()->format('H:i:s'),
            'end_time' => Carbon::parse($st->end_time)->addHour()->format('H:i:s'),
        ]);
        return $st->fresh();
    }

    private function pushToEvening(ScheduledTask $st): ScheduledTask
    {
        $evening = TimeBlock::query()
            ->where('name', 'Evening')
            ->orderBy('display_order')
            ->first();

        if (!$evening) {
            throw new RuntimeException('No evening block configured.');
        }

        $duration = Carbon::parse($st->start_time)->diffInMinutes(Carbon::parse($st->end_time));
        $start = Carbon::parse($evening->start_time);
        $end = $start->copy()->addMinutes($duration);

        $st->update([
            'time_block_id' => $evening->id,
            'start_time' => $start->format('H:i:s'),
            'end_time' => $end->format('H:i:s'),
        ]);
        return $st->fresh();
    }

    private function pushToTomorrow(ScheduledTask $st): ScheduledTask
    {
        $st->update([
            'scheduled_date' => Carbon::parse($st->scheduled_date)->addDay()->toDateString(),
        ]);
        return $st->fresh();
    }

    private function remove(ScheduledTask $st): ?ScheduledTask
    {
        $st->delete();
        return null;
    }

    private function setTime(ScheduledTask $st, array $payload): ScheduledTask
    {
        $st->update([
            'start_time' => $payload['start_time'],
            'end_time' => $payload['end_time'],
        ]);
        return $st->fresh();
    }
}
