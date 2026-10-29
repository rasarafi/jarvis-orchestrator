<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectColorsControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_slug_to_color_map(): void
    {
        Project::factory()->create(['slug' => 'a']);
        Project::factory()->create(['slug' => 'b']);

        $response = $this->getJson('/api/projects/colors');

        $response->assertOk();
        $colors = $response->json('data');

        $this->assertArrayHasKey('a', $colors);
        $this->assertArrayHasKey('b', $colors);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $colors['a']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $colors['b']);
    }

    public function test_returns_empty_data_when_no_projects(): void
    {
        $response = $this->getJson('/api/projects/colors');

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }
}
