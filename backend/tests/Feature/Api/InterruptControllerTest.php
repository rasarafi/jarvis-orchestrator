<?php

namespace Tests\Feature\Api;

use App\Models\Interrupt;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterruptControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_active_interrupts(): void
    {
        $project = Project::factory()->create();
        Interrupt::factory()->active()->create(['project_id' => $project->id]);
        Interrupt::factory()->resolved()->create(['project_id' => $project->id]);

        $response = $this->getJson('/api/interrupts');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_store_creates_interrupt(): void
    {
        $project = Project::factory()->create();

        $response = $this->postJson('/api/interrupts', [
            'project_id' => $project->id,
            'was_working_on' => 'Fixing login flow',
            'progress' => 'Halfway through',
            'resume_instructions' => 'Check auth middleware',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.was_working_on', 'Fixing login flow');
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->postJson('/api/interrupts', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['project_id', 'was_working_on']);
    }

    public function test_resolve_marks_interrupt_resolved(): void
    {
        $project = Project::factory()->create();
        $interrupt = Interrupt::factory()->active()->create(['project_id' => $project->id]);

        $response = $this->patchJson("/api/interrupts/{$interrupt->id}/resolve");

        $response->assertOk()
            ->assertJsonPath('data.status', 'resolved');
    }
}
