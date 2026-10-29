<?php

namespace Database\Factories;

use App\Enums\TimeBlockKind;
use App\Models\TimeBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

class TimeBlockFactory extends Factory
{
    protected $model = TimeBlock::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Morning', 'Afternoon']),
            'kind' => TimeBlockKind::WORK->value,
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'weekday_mask' => 127,
            'color_hint' => null,
            'display_order' => 0,
        ];
    }

    public function personal(): static
    {
        return $this->state(['kind' => TimeBlockKind::PERSONAL->value]);
    }

    public function evening(): static
    {
        return $this->state([
            'name' => 'Evening',
            'start_time' => '16:00:00',
            'end_time' => '24:00:00',
            'display_order' => 2,
        ]);
    }
}
