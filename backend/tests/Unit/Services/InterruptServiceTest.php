<?php

namespace Tests\Unit\Services;

use App\Enums\InterruptStatus;
use App\Models\Interrupt;
use App\Models\Project;
use App\Services\InterruptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InterruptServiceTest extends TestCase
{
    use RefreshDatabase;

    private InterruptService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InterruptService::class);
    }

    public function test_create_interrupt(): void
    {
        $project = Project::factory()->create();

        $interrupt = $this->service->createInterrupt($project, [
            'was_working_on' => 'Building auth',
            'progress' => 'Halfway',
            'resume_instructions' => 'Start with routes',
        ]);

        $this->assertDatabaseHas('interrupts', [
            'project_id' => $project->id,
            'was_working_on' => 'Building auth',
        ]);
        $this->assertEquals(InterruptStatus::ACTIVE, $interrupt->status);
    }

    public function test_create_interrupt_auto_resolves_existing(): void
    {
        $project = Project::factory()->create();

        $first = $this->service->createInterrupt($project, [
            'was_working_on' => 'First task',
        ]);

        $second = $this->service->createInterrupt($project, [
            'was_working_on' => 'Second task',
        ]);

        $this->assertEquals(InterruptStatus::RESOLVED, $first->fresh()->status);
        $this->assertNotNull($first->fresh()->resolved_at);
        $this->assertEquals(InterruptStatus::ACTIVE, $second->status);
    }

    public function test_create_interrupt_different_projects_independent(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();

        $interruptA = $this->service->createInterrupt($projectA, [
            'was_working_on' => 'Task A',
        ]);

        $interruptB = $this->service->createInterrupt($projectB, [
            'was_working_on' => 'Task B',
        ]);

        // Both should be active — different projects
        $this->assertEquals(InterruptStatus::ACTIVE, $interruptA->fresh()->status);
        $this->assertEquals(InterruptStatus::ACTIVE, $interruptB->fresh()->status);
    }

    public function test_get_active_interrupts_only(): void
    {
        $project = Project::factory()->create();
        Interrupt::factory()->create(['project_id' => $project->id, 'status' => 'active']);
        Interrupt::factory()->resolved()->create(['project_id' => $project->id]);

        $active = $this->service->getActiveInterrupts();

        $this->assertCount(1, $active);
    }

    public function test_get_active_interrupts_filter_by_project(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        Interrupt::factory()->create(['project_id' => $projectA->id, 'status' => 'active']);
        Interrupt::factory()->create(['project_id' => $projectB->id, 'status' => 'active']);

        $active = $this->service->getActiveInterrupts($projectA);

        $this->assertCount(1, $active);
        $this->assertEquals($projectA->id, $active->first()->project_id);
    }

    public function test_get_active_interrupts_returns_all_when_no_filter(): void
    {
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        Interrupt::factory()->create(['project_id' => $projectA->id, 'status' => 'active']);
        Interrupt::factory()->create(['project_id' => $projectB->id, 'status' => 'active']);

        $active = $this->service->getActiveInterrupts();

        $this->assertCount(2, $active);
    }

    public function test_resolve_interrupt_sets_timestamp(): void
    {
        $interrupt = Interrupt::factory()->create(['status' => 'active']);

        $resolved = $this->service->resolveInterrupt($interrupt);

        $this->assertEquals(InterruptStatus::RESOLVED, $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
    }
}
