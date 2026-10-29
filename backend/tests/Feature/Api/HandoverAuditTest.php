<?php // backend/tests/Feature/Api/HandoverAuditTest.php
namespace Tests\Feature\Api;

use App\Enums\AuditFindingStatus;
use App\Models\AuditFinding;
use App\Models\AuditRun;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandoverAuditTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(Project $project, array $attrs = []): AuditRun
    {
        return AuditRun::create(array_merge([
            'project_id' => $project->id,
            'status' => 'flagged',
            'gate_reached' => 1,
            'app_classification' => ['dashboard'],
            'summary' => 'test run',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ], $attrs));
    }

    private function makeFinding(AuditRun $run, array $attrs = []): AuditFinding
    {
        return AuditFinding::create(array_merge([
            'audit_run_id' => $run->id,
            'project_id' => $run->project_id,
            'gate' => 1,
            'module' => 'data-substance',
            'severity' => 'critical',
            'title' => 'MSP dashboard renders zero rows',
            'detail' => 'child count query returned 0; sibling HV returns 412',
            'evidence' => ['query' => 'select count(*) ...', 'count' => 0],
            'suppression_key' => 'data-substance:msp-dashboard:child-count',
        ], $attrs));
    }

    public function test_run_and_findings_persist_with_relations(): void
    {
        $project = Project::factory()->create();
        $run = $this->makeRun($project);
        $finding = $this->makeFinding($run);

        $this->assertSame($run->id, $finding->run->id);
        $this->assertSame($project->id, $finding->project->id);
        $this->assertSame(AuditFindingStatus::OPEN, $finding->status);
        $this->assertCount(1, $run->findings);
    }

    public function test_submit_run_persists_findings_and_suppresses_previously_dismissed(): void
    {
        $project = Project::factory()->create();

        // prior run: finding dismissed by user ("empty by design")
        $prior = $this->makeRun($project);
        $this->makeFinding($prior, [
            'status' => AuditFindingStatus::DISMISSED->value,
            'dismissed_at' => now(),
            'suppression_key' => 'data-substance:archived-users:count',
        ]);

        $run = app(\App\Services\AuditService::class)->submitRun($project, [
            'status' => 'flagged',
            'gate_reached' => 1,
            'app_classification' => ['dashboard'],
            'summary' => '1 critical, 1 suppressed',
            'findings' => [
                [
                    'gate' => 1, 'module' => 'data-substance', 'severity' => 'critical',
                    'title' => 'MSP dashboard empty',
                    'suppression_key' => 'data-substance:msp-dashboard:child-count',
                ],
                [
                    'gate' => 1, 'module' => 'data-substance', 'severity' => 'warning',
                    'title' => 'Archived users empty',
                    'suppression_key' => 'data-substance:archived-users:count',
                ],
            ],
        ]);

        $byKey = $run->findings->keyBy('suppression_key');
        $this->assertSame(AuditFindingStatus::OPEN, $byKey['data-substance:msp-dashboard:child-count']->status);
        $this->assertSame(AuditFindingStatus::SUPPRESSED, $byKey['data-substance:archived-users:count']->status);
    }

    public function test_suppressions_returns_distinct_dismissed_keys(): void
    {
        $project = Project::factory()->create();
        $run = $this->makeRun($project);
        $this->makeFinding($run, ['status' => 'dismissed', 'dismissed_at' => now(), 'suppression_key' => 'k1']);
        $this->makeFinding($run, ['status' => 'dismissed', 'dismissed_at' => now(), 'suppression_key' => 'k1']);
        $this->makeFinding($run, ['suppression_key' => 'k2']); // open — not a suppression

        $this->assertSame(['k1'], app(\App\Services\AuditService::class)->suppressions($project));
    }

    public function test_latest_returns_newest_run_with_findings(): void
    {
        $project = Project::factory()->create();
        $this->makeRun($project, ['created_at' => now()->subHour()]);
        $newest = $this->makeRun($project);
        $this->makeFinding($newest);

        $latest = app(\App\Services\AuditService::class)->latest($project);
        $this->assertSame($newest->id, $latest->id);
        $this->assertCount(1, $latest->findings);
    }

    public function test_dismiss_and_reopen_toggle_status(): void
    {
        $project = Project::factory()->create();
        $finding = $this->makeFinding($this->makeRun($project));
        $svc = app(\App\Services\AuditService::class);

        $svc->dismiss($finding);
        $this->assertSame(AuditFindingStatus::DISMISSED, $finding->fresh()->status);
        $this->assertNotNull($finding->fresh()->dismissed_at);

        $svc->reopen($finding->fresh());
        $this->assertSame(AuditFindingStatus::OPEN, $finding->fresh()->status);
        $this->assertNull($finding->fresh()->dismissed_at);
    }

    public function test_convert_to_task_creates_linked_task_with_evidence(): void
    {
        $project = Project::factory()->create();
        $finding = $this->makeFinding($this->makeRun($project));

        $task = app(\App\Services\AuditService::class)
            ->convertToTask($finding, \App\Enums\TaskActor::JARVIS);

        $fresh = $finding->fresh();
        $this->assertSame(AuditFindingStatus::CONVERTED, $fresh->status);
        $this->assertSame($task->id, $fresh->task_id);
        $this->assertSame($project->id, $task->project_id);
        $this->assertSame('high', $task->priority); // critical → high
        $this->assertStringContainsString('MSP dashboard renders zero rows', $task->title);
    }

    public function test_converted_finding_cannot_be_reopened_or_reconverted(): void
    {
        $project = Project::factory()->create();
        $finding = $this->makeFinding($this->makeRun($project));
        $svc = app(\App\Services\AuditService::class);
        $svc->convertToTask($finding, \App\Enums\TaskActor::JARVIS);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $svc->convertToTask($finding->fresh(), \App\Enums\TaskActor::JARVIS);
    }

    public function test_post_audits_stores_run_via_api(): void
    {
        $project = Project::factory()->create();

        $res = $this->postJson('/api/audits', [
            'project' => $project->slug,
            'status' => 'flagged',
            'gate_reached' => 1,
            'app_classification' => ['dashboard'],
            'summary' => '1 finding',
            'findings' => [[
                'gate' => 1, 'module' => 'data-substance', 'severity' => 'critical',
                'title' => 'Empty dashboard', 'suppression_key' => 'data-substance:x:y',
            ]],
        ]);

        $res->assertOk()->assertJsonPath('data.run.status', 'flagged');
        $this->assertDatabaseCount('audit_findings', 1);
    }

    public function test_latest_and_suppressions_endpoints(): void
    {
        $project = Project::factory()->create();
        $run = $this->makeRun($project);
        $this->makeFinding($run, ['status' => 'dismissed', 'dismissed_at' => now(), 'suppression_key' => 'k1']);

        $this->getJson("/api/audits/latest?project={$project->slug}")
            ->assertOk()->assertJsonPath('data.run.id', $run->id);
        $this->getJson("/api/audits/suppressions?project={$project->slug}")
            ->assertOk()->assertJsonPath('data.0', 'k1');
    }

    public function test_finding_actions_via_api(): void
    {
        $project = Project::factory()->create();
        $finding = $this->makeFinding($this->makeRun($project));

        $this->patchJson("/api/audits/findings/{$finding->id}/dismiss")
            ->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->patchJson("/api/audits/findings/{$finding->id}/reopen")
            ->assertOk()->assertJsonPath('data.status', 'open');
        $this->postJson("/api/audits/findings/{$finding->id}/convert-to-task", [], ['X-Jarvis-Actor' => 'jarvis'])
            ->assertOk()->assertJsonPath('data.finding.status', 'converted');
    }

    public function test_post_audits_validates_severity_and_status(): void
    {
        $project = Project::factory()->create();
        $this->postJson('/api/audits', [
            'project' => $project->slug, 'status' => 'bogus', 'gate_reached' => 1, 'findings' => [],
        ])->assertStatus(422);
    }

    public function test_recent_returns_cross_project_runs_with_counts(): void
    {
        $a = Project::factory()->create();
        $b = Project::factory()->create();
        $older = $this->makeRun($a);
        AuditRun::whereKey($older->id)->update(['created_at' => now()->subHour()]);
        $newer = $this->makeRun($b);
        $this->makeFinding($newer);
        $this->makeFinding($newer, [
            'severity' => 'warning',
            'suppression_key' => 'ux:other',
            'status' => AuditFindingStatus::DISMISSED->value,
        ]);

        $data = $this->getJson('/api/audits/recent')->assertOk()->json('data');

        $this->assertSame([$newer->id, $older->id], array_column($data, 'id'));
        $this->assertSame((string) $b->slug, $data[0]['project']['slug']);
        $this->assertSame(2, $data[0]['findings_count']);
        $this->assertSame(1, $data[0]['open_findings_count']);
        $this->assertSame(1, $data[0]['critical_findings_count']);
        $this->assertSame(0, $data[1]['findings_count']);
    }
}
