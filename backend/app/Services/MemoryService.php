<?php

namespace App\Services;

use App\Models\DailyPlan;
use App\Models\Knowledge;
use App\Models\Note;
use App\Models\WeeklyReview;
use App\Models\WorkSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MemoryService
{
    private ?string $apiKey;
    private ?string $indexHost;

    public function __construct()
    {
        $this->apiKey = config('pinecone.api_key');
        $this->indexHost = config('pinecone.index_host');
    }

    /**
     * Index a record to Pinecone.
     * Fire-and-forget: logs errors, never throws.
     */
    public function index(string $id, string $content, array $metadata): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        try {
            $record = array_merge(['_id' => $id, 'content' => $content], $metadata);

            Http::withHeaders([
                'Api-Key' => $this->apiKey,
                'X-Pinecone-API-Version' => '2025-04',
            ])->post("{$this->indexHost}/records/namespaces/default/upsert", $record);
        } catch (\Throwable $e) {
            Log::warning("Pinecone index failed for {$id}: {$e->getMessage()}");
        }
    }

    public function indexNote(Note $note): void
    {
        $this->index("note-{$note->id}", $note->body, [
            'type' => 'note',
            'project_slug' => $note->project?->slug ?? '',
            'source' => $note->source->value,
        ]);
    }

    public function indexWorkSession(WorkSession $session): void
    {
        if (!$session->summary || $session->summary === "Session: {$session->cc_session_id}") {
            return;
        }

        $content = $session->summary;
        if (!empty($session->decisions)) {
            $decisions = is_array($session->decisions) ? implode('; ', $session->decisions) : $session->decisions;
            $content .= "\nDecisions: {$decisions}";
        }

        $projectSlug = $session->project?->slug;

        $this->index("worksession-{$session->id}", $content, [
            'project_slug' => $projectSlug ?? '',
            'source' => 'work_session',
            'created_at' => $session->started_at?->toDateString() ?? now()->toDateString(),
            'type' => 'summary',
        ]);
    }

    public function indexKnowledge(Knowledge $knowledge): void
    {
        $content = "{$knowledge->title}: {$knowledge->content}";
        $projectSlug = $knowledge->project?->slug ?? '';

        $this->index("knowledge-{$knowledge->id}", $content, [
            'project_slug' => $projectSlug,
            'source' => 'knowledge',
            'created_at' => $knowledge->created_at->toDateString(),
            'type' => $knowledge->type ?? 'pattern',
        ]);
    }

    public function indexWeeklyReview(WeeklyReview $review): void
    {
        $content = $review->summary;
        if (!empty($review->behavior_insights)) {
            $insights = is_array($review->behavior_insights) ? implode('; ', $review->behavior_insights) : $review->behavior_insights;
            $content .= "\nInsights: {$insights}";
        }

        $this->index("review-{$review->id}", $content, [
            'project_slug' => '',
            'source' => 'weekly_review',
            'created_at' => $review->week_start->toDateString(),
            'type' => 'insight',
        ]);
    }

    public function indexDailyPlan(DailyPlan $plan): void
    {
        if (!$plan->actual_outcome) {
            return;
        }

        $this->index("plan-{$plan->id}", $plan->actual_outcome, [
            'project_slug' => '',
            'source' => 'daily_plan',
            'created_at' => $plan->date->toDateString(),
            'type' => 'outcome',
        ]);
    }

    /**
     * Semantic search against Pinecone.
     * Returns array of hits: [['id' => ..., 'score' => ..., 'content' => ..., 'metadata' => [...]], ...]
     */
    public function recall(string $query, int $topK = 5, ?array $filter = null): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            $body = [
                'query' => [
                    'inputs' => ['text' => $query],
                    'top_k' => $topK,
                ],
                'fields' => ['content', 'project_slug', 'source', 'type', 'created_at'],
            ];

            if ($filter) {
                $body['query']['filter'] = $filter;
            }

            $response = Http::withHeaders([
                'Api-Key' => $this->apiKey,
                'X-Pinecone-API-Version' => '2025-04',
            ])->post("{$this->indexHost}/records/namespaces/default/search", $body);

            if (!$response->successful()) {
                Log::warning("Pinecone search failed: {$response->body()}");
                return [];
            }

            $hits = $response->json('result.hits', []);

            return array_map(fn ($hit) => [
                'id' => $hit['_id'],
                'score' => $hit['_score'],
                'content' => $hit['fields']['content'] ?? '',
                'source' => $hit['fields']['source'] ?? '',
                'type' => $hit['fields']['type'] ?? '',
                'project_slug' => $hit['fields']['project_slug'] ?? '',
                'created_at' => $hit['fields']['created_at'] ?? '',
            ], $hits);
        } catch (\Throwable $e) {
            Log::warning("Pinecone recall failed: {$e->getMessage()}");
            return [];
        }
    }

    public function recallForProject(string $query, string $projectSlug, int $topK = 5): array
    {
        return $this->recall($query, $topK, [
            'project_slug' => ['$eq' => $projectSlug],
        ]);
    }

    /**
     * Bulk seed existing Postgres data into Pinecone.
     * Returns count of records indexed.
     */
    public function seedFromPostgres(): int
    {
        $count = 0;

        // Work sessions with real summaries
        WorkSession::with('project')
            ->whereNotNull('summary')
            ->where('summary', 'not like', 'Session: %')
            ->chunk(50, function ($sessions) use (&$count) {
                foreach ($sessions as $session) {
                    $this->indexWorkSession($session);
                    $count++;
                }
            });

        // Knowledge entries
        Knowledge::with('project')
            ->chunk(50, function ($entries) use (&$count) {
                foreach ($entries as $entry) {
                    $this->indexKnowledge($entry);
                    $count++;
                }
            });

        // Weekly reviews
        WeeklyReview::chunk(50, function ($reviews) use (&$count) {
            foreach ($reviews as $review) {
                $this->indexWeeklyReview($review);
                $count++;
            }
        });

        // Daily plans with outcomes
        DailyPlan::whereNotNull('actual_outcome')
            ->chunk(50, function ($plans) use (&$count) {
                foreach ($plans as $plan) {
                    $this->indexDailyPlan($plan);
                    $count++;
                }
            });

        return $count;
    }

    public function delete(string $id): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        try {
            Http::withHeaders([
                'Api-Key' => $this->apiKey,
                'X-Pinecone-API-Version' => '2025-04',
            ])->delete("{$this->indexHost}/records/namespaces/default/records", [
                'ids' => [$id],
            ]);
        } catch (\Throwable $e) {
            Log::warning("Pinecone delete failed for {$id}: {$e->getMessage()}");
        }
    }

    private function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->indexHost);
    }
}
