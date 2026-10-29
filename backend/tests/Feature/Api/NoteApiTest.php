<?php

namespace Tests\Feature\Api;

use App\Enums\NoteStatus;
use App\Models\Note;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_defaults_to_inbox_oldest_first_with_counts(): void
    {
        $old = Note::factory()->create(['created_at' => now()->subDays(2)]);
        $new = Note::factory()->create(['created_at' => now()->subDay()]);
        Note::factory()->create(['status' => NoteStatus::KEPT]);

        $res = $this->getJson('/api/notes')->assertOk();
        $this->assertSame($old->id, $res->json('data.0.id'));
        $this->assertSame($new->id, $res->json('data.1.id'));
        $this->assertSame(2, $res->json('meta.counts.inbox'));
        $this->assertSame(1, $res->json('meta.counts.kept'));
    }

    public function test_kept_orders_pinned_first(): void
    {
        $plain = Note::factory()->create(['status' => NoteStatus::KEPT, 'created_at' => now()]);
        $pinned = Note::factory()->create(['status' => NoteStatus::KEPT, 'pinned' => true, 'created_at' => now()->subWeek()]);

        $res = $this->getJson('/api/notes?status=kept')->assertOk();
        $this->assertSame($pinned->id, $res->json('data.0.id'));
        $this->assertSame($plain->id, $res->json('data.1.id'));
    }

    public function test_capture_and_empty_body_rejected(): void
    {
        $this->postJson('/api/notes', ['body' => 'quick thought'])->assertCreated();
        $this->postJson('/api/notes', ['body' => ''])->assertStatus(422);
    }

    public function test_status_transitions(): void
    {
        $note = Note::factory()->create();
        $this->patchJson("/api/notes/{$note->id}/keep")->assertOk();
        $this->assertSame('kept', $note->fresh()->status->value);
        $this->patchJson("/api/notes/{$note->id}/archive")->assertOk();
        $this->patchJson("/api/notes/{$note->id}/restore")->assertOk();
        $this->assertSame('inbox', $note->fresh()->status->value);
    }

    public function test_convert_endpoint(): void
    {
        $project = Project::factory()->create();
        $note = Note::factory()->create(['project_id' => $project->id, 'body' => 'Ship the thing']);

        $res = $this->postJson("/api/notes/{$note->id}/convert-to-task", ['priority' => 'high'])
            ->assertCreated();
        $this->assertSame('Ship the thing', $res->json('data.title'));
        $this->assertSame('archived', $note->fresh()->status->value);

        $this->postJson("/api/notes/{$note->id}/convert-to-task", [])->assertStatus(422);
    }

    public function test_promote_endpoint_requires_title_and_type(): void
    {
        $note = Note::factory()->create();
        $this->postJson("/api/notes/{$note->id}/promote-to-knowledge", [])->assertStatus(422);
        $this->postJson("/api/notes/{$note->id}/promote-to-knowledge", [
            'title' => 'A pattern', 'type' => 'pattern',
        ])->assertCreated();
    }

    public function test_delete(): void
    {
        $note = Note::factory()->create();
        $this->deleteJson("/api/notes/{$note->id}")->assertOk();
        $this->assertSame(0, Note::count());
    }
}
