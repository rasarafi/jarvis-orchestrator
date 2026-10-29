<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\WorkSession;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkSessionFactory extends Factory
{
    protected $model = WorkSession::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active', 'ended_at' => null]);
    }

    public function withSummary(string $summary = 'Did some work'): static
    {
        return $this->state(['summary' => $summary]);
    }
}
