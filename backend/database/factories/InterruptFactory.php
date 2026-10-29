<?php

namespace Database\Factories;

use App\Models\Interrupt;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class InterruptFactory extends Factory
{
    protected $model = Interrupt::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'was_working_on' => 'Building auth module',
            'progress' => 'Middleware done, routes pending',
            'resume_instructions' => 'Start with routes/api.php, add auth middleware group',
            'status' => 'active',
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function resolved(): static
    {
        return $this->state(['status' => 'resolved', 'resolved_at' => now()]);
    }
}
