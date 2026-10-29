<?php

namespace Tests\Feature\Api;

use App\Models\TimeBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeBlockControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_all_blocks(): void
    {
        TimeBlock::factory()->count(2)->create();

        $response = $this->getJson('/api/time-blocks');

        $response->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_store_creates_block(): void
    {
        $response = $this->postJson('/api/time-blocks', [
            'name' => 'Morning',
            'kind' => 'work',
            'start_time' => '08:00:00',
            'end_time' => '12:00:00',
            'weekday_mask' => 127,
        ]);

        $response->assertCreated()->assertJsonPath('data.name', 'Morning');
        $this->assertDatabaseHas('time_blocks', ['name' => 'Morning']);
    }

    public function test_update_modifies_block(): void
    {
        $block = TimeBlock::factory()->create(['name' => 'Old']);

        $response = $this->patchJson("/api/time-blocks/{$block->id}", ['name' => 'New']);

        $response->assertOk()->assertJsonPath('data.name', 'New');
    }

    public function test_destroy_removes_block(): void
    {
        $block = TimeBlock::factory()->create();

        $response = $this->deleteJson("/api/time-blocks/{$block->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('time_blocks', ['id' => $block->id]);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->postJson('/api/time-blocks', []);

        $response->assertStatus(422);
    }
}
