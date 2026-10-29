<?php

namespace Database\Factories;

use App\Models\ScheduledTask;
use App\Models\Task;
use App\Models\TimeBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledTaskFactory extends Factory
{
    protected $model = ScheduledTask::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'time_block_id' => TimeBlock::factory(),
            'scheduled_date' => now()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'position' => 0,
        ];
    }

    public function forDate(string $date): static
    {
        return $this->state(['scheduled_date' => $date]);
    }
}
