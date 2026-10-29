<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'status' => 'todo',
            'priority' => 'medium',
        ];
    }

    public function urgent(): static
    {
        return $this->state(['priority' => 'urgent']);
    }

    public function high(): static
    {
        return $this->state(['priority' => 'high']);
    }

    public function low(): static
    {
        return $this->state(['priority' => 'low']);
    }

    public function done(): static
    {
        return $this->state(['status' => 'done', 'completed_at' => now()]);
    }

    public function blocked(string $reason = 'Waiting on client'): static
    {
        return $this->state(['status' => 'blocked', 'blocker_reason' => $reason]);
    }

    public function withDeadline(int $daysFromNow = 3): static
    {
        return $this->state(['deadline' => now()->addDays($daysFromNow)]);
    }

    public function withoutProject(): static
    {
        return $this->state(['project_id' => null]);
    }
}
