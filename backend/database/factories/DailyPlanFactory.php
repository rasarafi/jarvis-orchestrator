<?php

namespace Database\Factories;

use App\Models\DailyPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class DailyPlanFactory extends Factory
{
    protected $model = DailyPlan::class;

    public function definition(): array
    {
        return [
            'date' => now()->toDateString(),
            'must_do' => 'Complete feature X',
            'should_do' => 'Review PR for project Y',
            'could_do' => 'Refactor auth module',
            'raw_output' => 'Full morning briefing output here.',
        ];
    }

    public function forDate(string $date): static
    {
        return $this->state(['date' => $date]);
    }

    public function completed(): static
    {
        return $this->state([
            'must_do_completed' => true,
            'should_do_completed' => true,
            'could_do_completed' => true,
        ]);
    }

    public function mustDoCompleted(): static
    {
        return $this->state(['must_do_completed' => true]);
    }
}
