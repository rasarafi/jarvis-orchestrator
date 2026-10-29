<?php

namespace Database\Factories;

use App\Enums\FocusSessionStatus;
use App\Models\FocusSession;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class FocusSessionFactory extends Factory
{
    protected $model = FocusSession::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'status' => FocusSessionStatus::ACTIVE->value,
            'started_at' => now(),
            'ended_at' => null,
            'elapsed_seconds' => 0,
            'interruptions' => 0,
        ];
    }

    public function ended(): static
    {
        return $this->state([
            'status' => FocusSessionStatus::ENDED->value,
            'ended_at' => now(),
            'elapsed_seconds' => 1500,
        ]);
    }
}
