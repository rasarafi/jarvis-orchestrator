<?php

namespace App\Models;

use App\Enums\NoteSource;
use App\Enums\NoteStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'project_id', 'body', 'source', 'status', 'pinned',
        'remote_id', 'converted_task_id', 'promoted_knowledge_id',
    ];

    protected $attributes = [
        'source' => 'deck',
        'status' => 'inbox',
        'pinned' => false,
    ];

    protected $casts = [
        'source' => NoteSource::class,
        'status' => NoteStatus::class,
        'pinned' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function convertedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'converted_task_id');
    }

    public function promotedKnowledge(): BelongsTo
    {
        return $this->belongsTo(Knowledge::class, 'promoted_knowledge_id');
    }
}
