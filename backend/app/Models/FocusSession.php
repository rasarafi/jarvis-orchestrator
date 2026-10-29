<?php

namespace App\Models;

use App\Enums\FocusSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FocusSession extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'focus_sessions';

    protected $fillable = [
        'task_id',
        'status',
        'started_at',
        'ended_at',
        'elapsed_seconds',
        'interruptions',
        'notes',
    ];

    protected $casts = [
        'status' => FocusSessionStatus::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'elapsed_seconds' => 'integer',
        'interruptions' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Scope: only active or paused sessions (not yet ended).
     * SINGLE SOURCE OF TRUTH for "open session" filter.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', FocusSessionStatus::openValues());
    }
}
