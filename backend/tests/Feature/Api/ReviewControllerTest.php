<?php

namespace Tests\Feature\Api;

use App\Models\WeeklyReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_returns_this_weeks_review(): void
    {
        $review = WeeklyReview::factory()->create([
            'week_start' => now()->startOfWeek()->toDateString(),
        ]);

        $response = $this->getJson('/api/reviews/current');

        $response->assertOk();
    }

    public function test_current_returns_null_when_no_review(): void
    {
        $response = $this->getJson('/api/reviews/current');

        $response->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_store_creates_review(): void
    {
        $response = $this->postJson('/api/reviews', [
            'week_start' => now()->startOfWeek()->toDateString(),
            'summary' => 'Good productive week',
            'client_digests' => ['client1' => 'delivered feature X'],
            'behavior_insights' => ['Worked too many late nights'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('weekly_reviews', ['summary' => 'Good productive week']);
    }
}
