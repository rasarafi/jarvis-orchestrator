<?php

namespace Tests\Feature;

use App\Enums\NoteStatus;
use App\Models\Note;
use App\Models\Project;
use App\Services\NoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): NoteService
    {
        return app(NoteService::class);
    }

    public function test_capture_is_idempotent_on_remote_id(): void
    {
        $a = $this->service()->capture(['body' => 'from phone', 'remote_id' => 5]);
        $b = $this->service()->capture(['body' => 'from phone', 'remote_id' => 5]);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Note::count());
    }

    public function test_lifecycle_transitions(): void
    {
        $note = Note::factory()->create();
        $this->assertSame(NoteStatus::KEPT, $this->service()->keep($note)->status);
        $this->assertSame(NoteStatus::ARCHIVED, $this->service()->archive($note)->status);
        $this->assertSame(NoteStatus::INBOX, $this->service()->restore($note)->status);
        $this->assertTrue($this->service()->pin($note, true)->pinned);
    }

    public function test_convert_to_task_archives_and_links(): void
    {
        $project = Project::factory()->create();
        $note = Note::factory()->create(['body' => 'Call the bank about the license', 'project_id' => $project->id]);

        $task = $this->service()->convertToTask($note, ['priority' => 'high']);

        $this->assertSame('Call the bank about the license', $task->title);
        $this->assertSame($project->id, $task->project_id);
        $this->assertSame('jarvis', $task->source->value);
        $note->refresh();
        $this->assertSame(NoteStatus::ARCHIVED, $note->status);
        $this->assertSame($task->id, $note->converted_task_id);
    }

    public function test_convert_archived_note_rejected(): void
    {
        $note = Note::factory()->create(['status' => NoteStatus::ARCHIVED]);
        $this->expectException(ValidationException::class);
        $this->service()->convertToTask($note, []);
    }

    public function test_promote_to_knowledge(): void
    {
        $project = Project::factory()->create();
        $note = Note::factory()->create(['body' => 'PayOK escalation: WhatsApp Andres first', 'project_id' => $project->id]);

        $k = $this->service()->promoteToKnowledge($note, ['title' => 'PayOK escalation path', 'type' => 'pattern']);

        $this->assertSame('PayOK escalation path', $k->title);
        $this->assertSame('PayOK escalation: WhatsApp Andres first', $k->content);
        $this->assertSame('project', $k->scope);
        $note->refresh();
        $this->assertSame(NoteStatus::ARCHIVED, $note->status);
        $this->assertSame($k->id, $note->promoted_knowledge_id);
    }

    public function test_counts(): void
    {
        Note::factory()->count(2)->create();
        Note::factory()->create(['status' => NoteStatus::KEPT]);

        $this->assertSame(['inbox' => 2, 'kept' => 1, 'archived' => 0], $this->service()->counts());
    }
}
