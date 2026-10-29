<?php

namespace Database\Factories;

use App\Models\WeeklyReview;
use Illuminate\Database\Eloquent\Factories\Factory;

class WeeklyReviewFactory extends Factory
{
    protected $model = WeeklyReview::class;

    public function definition(): array
    {
        return [
            'week_start' => now()->startOfWeek()->toDateString(),
            'summary' => 'Productive week. Shipped 3 features.',
            'client_digests' => ['ClientA' => 'Dashboard complete', 'ClientB' => 'Bug fixes deployed'],
            'next_week_plan' => 'Focus on API integration.',
        ];
    }
}
