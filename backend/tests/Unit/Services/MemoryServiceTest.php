<?php

namespace Tests\Unit\Services;

use App\Models\DailyPlan;
use App\Models\Knowledge;
use App\Models\Project;
use App\Models\WeeklyReview;
use App\Models\WorkSession;
use App\Services\MemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private MemoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pinecone.api_key' => 'test-key', 'pinecone.index_host' => 'https://test-host.pinecone.io']);
        $this->service = app(MemoryService::class);
    }

    public function test_index_sends_correct_http_request(): void
    {
        Http::fake(['*' => Http::response(null, 200)]);

        $this->service->index('test-001', 'Some content', [
            'source' => 'test',
            'type' => 'pattern',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/records/namespaces/default/upsert')
                && $request['_id'] === 'test-001'
                && $request['content'] === 'Some content'
                && $request['source'] === 'test';
        });
    }

    public function test_recall_parses_response_correctly(): void
    {
        Http::fake(['*' => Http::response([
            'result' => [
                'hits' => [
                    [
                        '_id' => 'worksession-abc',
                        '_score' => 0.95,
                        'fields' => [
                            'content' => 'Fixed caching bug',
                            'source' => 'work_session',
                            'type' => 'summary',
                            'project_slug' => 'jarvis',
                            'created_at' => '2026-02-20',
                        ],
                    ],
                ],
            ],
        ], 200)]);

        $results = $this->service->recall('caching decision');

        $this->assertCount(1, $results);
        $this->assertEquals('worksession-abc', $results[0]['id']);
        $this->assertEquals(0.95, $results[0]['score']);
        $this->assertEquals('Fixed caching bug', $results[0]['content']);
        $this->assertEquals('work_session', $results[0]['source']);
    }

    public function test_recall_for_project_adds_filter(): void
    {
        Http::fake(['*' => Http::response(['result' => ['hits' => []]], 200)]);

        $this->service->recallForProject('auth decision', 'jarvis');

        Http::assertSent(function ($request) {
            $body = $request->data();
            return isset($body['query']['filter']['project_slug']['$eq'])
                && $body['query']['filter']['project_slug']['$eq'] === 'jarvis';
        });
    }

    public function test_index_is_fire_and_forget_on_http_error(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        // Should not throw even on 500
        $this->service->index('fail-001', 'Will fail', ['source' => 'test']);
        $this->assertTrue(true); // No exception = pass
    }

    public function test_index_is_fire_and_forget_on_exception(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('Connection refused');
        });

        // Should not throw
        $this->service->index('fail-002', 'Will fail', ['source' => 'test']);
        $this->assertTrue(true);
    }

    public function test_returns_empty_when_not_configured(): void
    {
        config(['pinecone.api_key' => '', 'pinecone.index_host' => '']);
        $service = app(MemoryService::class);

        Http::fake();

        $service->index('skip-001', 'Should not send', ['source' => 'test']);
        $results = $service->recall('anything');

        Http::assertNothingSent();
        $this->assertEmpty($results);
    }

    public function test_index_work_session_with_summary(): void
    {
        Http::fake(['*' => Http::response(null, 200)]);

        $project = Project::factory()->create(['slug' => 'testproj']);
        $session = WorkSession::factory()->create([
            'project_id' => $project->id,
            'summary' => 'Implemented user auth',
            'decisions' => ['Use JWT tokens'],
            'started_at' => now(),
        ]);

        $this->service->indexWorkSession($session->load('project'));

        Http::assertSent(function ($request) {
            return str_contains($request['_id'], 'worksession-')
                && str_contains($request['content'], 'Implemented user auth')
                && str_contains($request['content'], 'JWT tokens')
                && $request['project_slug'] === 'testproj';
        });
    }

    public function test_index_work_session_skips_default_summary(): void
    {
        Http::fake();

        $session = WorkSession::factory()->create([
            'cc_session_id' => 'abc123',
            'summary' => 'Session: abc123',
        ]);

        $this->service->indexWorkSession($session);

        Http::assertNothingSent();
    }

    public function test_index_daily_plan_skips_without_outcome(): void
    {
        Http::fake();

        $plan = DailyPlan::factory()->create(['actual_outcome' => null]);

        $this->service->indexDailyPlan($plan);

        Http::assertNothingSent();
    }

    public function test_index_daily_plan_with_outcome(): void
    {
        Http::fake(['*' => Http::response(null, 200)]);

        $plan = DailyPlan::factory()->create(['actual_outcome' => 'Shipped the feature']);

        $this->service->indexDailyPlan($plan);

        Http::assertSent(function ($request) {
            return str_contains($request['_id'], 'plan-')
                && $request['content'] === 'Shipped the feature'
                && $request['source'] === 'daily_plan';
        });
    }

    public function test_seed_from_postgres_counts_records(): void
    {
        Http::fake(['*' => Http::response(null, 200)]);

        $project = Project::factory()->create();
        WorkSession::factory()->create([
            'project_id' => $project->id,
            'summary' => 'Real summary',
        ]);
        DailyPlan::factory()->create(['actual_outcome' => 'Got things done']);

        $count = $this->service->seedFromPostgres();

        $this->assertEquals(2, $count);
    }
}
