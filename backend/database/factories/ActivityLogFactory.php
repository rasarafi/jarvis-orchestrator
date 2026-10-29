<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ActivityLogFactory extends Factory
{
    protected $model = ActivityLog::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'type' => 'commit',
            'message' => fake()->sentence(),
        ];
    }

    public function sessionStart(): static
    {
        return $this->state(['type' => 'session_start', 'message' => 'Session started']);
    }

    public function system(): static
    {
        return $this->state(['project_id' => null, 'type' => 'system']);
    }
}
