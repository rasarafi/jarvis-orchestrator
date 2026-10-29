<?php

namespace App\Models;

use App\Enums\TaskActor;
use App\Enums\TaskEventType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['actor', 'event_type', 'payload'];

    protected $casts = [
        'actor' => TaskActor::class,
        'event_type' => TaskEventType::class,
        'payload' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
