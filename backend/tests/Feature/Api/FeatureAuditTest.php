<?php // backend/tests/Feature/Api/FeatureAuditTest.php
namespace Tests\Feature\Api;

use App\Enums\AuditScope;
use App\Models\AuditRun;
use App\Models\Project;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureAuditTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(Project $project, array $attrs = []): AuditRun
    {
        return AuditRun::create(array_merge([
            'project_id' => $project->id,
            'status' => 'flagged',
            'gate_reached' => 2,
            'app_classification' => ['dashboard'],
            'summary' => 'test run',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ], $attrs));
    }

    public function test_submit_persists_scope_and_feature(): void
    {
        $project = Project::factory()->create();

        $run = app(AuditService::class)->submitRun($project, [
            'scope' => 'feature',
            'feature' => 'commit-sweep',
            'status' => 'flagged',
            'gate_reached' => 2,
            'findings' => [[
                'gate' => 1,
                'module' => 'conformance',
                'severity' => 'warning',
                'title' => 'Undo not wired',
                'suppression_key' => 'feature:commit-sweep:conformance:undo',
            ]],
        ]);

        $this->assertSame(AuditScope::FEATURE, $run->scope);
        $this->assertSame('commit-sweep', $run->feature);
        $this->assertCount(1, $run->findings);
    }

    public function test_submit_defaults_scope_to_system(): void
    {
        $project = Project::factory()->create();

        $run = app(AuditService::class)->submitRun($project, [
            'status' => 'passed',
            'gate_reached' => 2,
            'findings' => [],
        ]);

        $this->assertSame(AuditScope::SYSTEM, $run->scope);
        $this->assertNull($run->feature);
    }

    public function test_latest_isolates_feature_from_system(): void
    {
        $project = Project::factory()->create();

        $system = $this->makeRun($project, ['created_at' => now()->subHour()]);
        // feature run is NEWER than the system run
        $feature = $this->makeRun($project, [
            'scope' => 'feature',
            'feature' => 'commit-sweep',
            'created_at' => now(),
        ]);

        $svc = app(AuditService::class);

        // no feature arg → newest SYSTEM run, not the newer feature run
        $this->assertSame($system->id, $svc->latest($project)?->id);
        // feature arg → the feature run
        $this->assertSame($feature->id, $svc->latest($project, 'commit-sweep')?->id);
    }

    public function test_features_endpoint_lists_audited_features(): void
    {
        $project = Project::factory()->create();
        $this->makeRun($project, ['scope' => 'feature', 'feature' => 'commit-sweep']);
        $this->makeRun($project); // a system run — must NOT appear in features

        $this->getJson("/api/audits/features?project={$project->slug}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.feature', 'commit-sweep');
    }

    public function test_post_requires_feature_when_scope_is_feature(): void
    {
        $project = Project::factory()->create();

        $this->postJson('/api/audits', [
            'project' => $project->slug,
            'scope' => 'feature',
            'status' => 'passed',
            'gate_reached' => 0,
            'findings' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('feature');
    }

    public function test_post_feature_run_via_api(): void
    {
        $project = Project::factory()->create();

        $this->postJson('/api/audits', [
            'project' => $project->slug,
            'scope' => 'feature',
            'feature' => 'commit-sweep',
            'status' => 'flagged',
            'gate_reached' => 2,
            'findings' => [],
        ])->assertOk()->assertJsonPath('data.run.scope', 'feature')
            ->assertJsonPath('data.run.feature', 'commit-sweep');
    }
}
