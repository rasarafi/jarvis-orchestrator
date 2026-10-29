<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'slug' => str($name)->slug(),
            'name' => $name,
            'purpose' => fake()->sentence(),
            'stack' => ['php' => '8.4', 'laravel' => '11'],
            'local_path' => '/home/dev/projects/' . str($name)->slug(),
            'status' => 'active',
        ];
    }

    public function paused(): static
    {
        return $this->state(['status' => 'paused']);
    }

    public function withPath(string $path): static
    {
        return $this->state(['local_path' => $path]);
    }

    public function withDna(): static
    {
        return $this->state([
            'key_modules' => ['Auth', 'Billing', 'API'],
            'known_risks' => 'Legacy payment gateway may timeout under load',
        ]);
    }
}
