<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RejectForeignOriginTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_site_request_is_rejected(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->postJson('/api/notes', ['body' => 'planted'])
            ->assertForbidden();

        $this->assertDatabaseCount('notes', 0);
    }

    public function test_cross_site_read_is_rejected(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->getJson('/api/projects')
            ->assertForbidden();
    }

    public function test_the_deck_origin_is_allowed(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:7171'])
            ->getJson('/api/projects')
            ->assertOk();
    }

    public function test_requests_without_origin_are_allowed(): void
    {
        $this->getJson('/api/projects')->assertOk();
    }
}
