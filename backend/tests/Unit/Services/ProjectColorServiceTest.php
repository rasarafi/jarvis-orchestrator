<?php

namespace Tests\Unit\Services;

use App\Models\Project;
use App\Services\ProjectColorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectColorServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProjectColorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProjectColorService::class);
    }

    public function test_color_for_slug_is_deterministic(): void
    {
        $a = $this->service->colorForSlug('acme-shop');
        $b = $this->service->colorForSlug('acme-shop');

        $this->assertSame($a, $b);
    }

    public function test_different_slugs_produce_different_colors(): void
    {
        $a = $this->service->colorForSlug('acme-shop');
        $b = $this->service->colorForSlug('globex');

        $this->assertNotSame($a, $b);
    }

    public function test_color_is_valid_hex_string(): void
    {
        $color = $this->service->colorForSlug('any-project');

        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $color);
    }

    public function test_all_colors_returns_map_for_all_projects(): void
    {
        Project::factory()->create(['slug' => 'a']);
        Project::factory()->create(['slug' => 'b']);

        $map = $this->service->allColors();

        $this->assertArrayHasKey('a', $map);
        $this->assertArrayHasKey('b', $map);
        $this->assertSame($this->service->colorForSlug('a'), $map['a']);
    }
}
