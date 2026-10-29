<?php

namespace Tests\Feature;

use App\Enums\NoteSource;
use App\Enums\NoteStatus;
use App\Models\Note;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoteModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_note_defaults_and_casts(): void
    {
        $note = Note::factory()->create(['body' => 'remember this']);

        $this->assertSame(NoteStatus::INBOX, $note->fresh()->status);
        $this->assertSame(NoteSource::DECK, $note->fresh()->source);
        $this->assertFalse($note->fresh()->pinned);
        $this->assertNull($note->project_id);
    }

    public function test_remote_id_is_unique(): void
    {
        Note::factory()->create(['remote_id' => 77]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Note::factory()->create(['remote_id' => 77]);
    }
}
