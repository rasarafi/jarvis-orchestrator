<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_recall_returns_results(): void
    {
        // Pinecone not configured in test — returns empty array gracefully
        $response = $this->postJson('/api/memory/recall', [
            'query' => 'auth bug fix',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_recall_validates_query(): void
    {
        $response = $this->postJson('/api/memory/recall', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['query']);
    }

    public function test_remember_stores_memory(): void
    {
        Http::fake(); // Prevent actual Pinecone calls

        $response = $this->postJson('/api/memory/remember', [
            'content' => 'Always check migration status before deploying',
            'type' => 'insight',
            'project_slug' => 'jarvis',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Memory stored');
    }

    public function test_remember_validates_content(): void
    {
        $response = $this->postJson('/api/memory/remember', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }
}
