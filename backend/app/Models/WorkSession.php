<?php

namespace App\Models;

use App\Enums\WorkSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSession extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'work_sessions';

    protected $fillable = [
        'project_id',
        'cc_session_id',
        'status',
        'started_at',
        'ended_at',
        'summary',
        'decisions',
        'tasks_done',
        'tasks_next',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'decisions' => 'array',
        'tasks_done' => 'array',
        'tasks_next' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Scope: Get only active sessions
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', WorkSessionStatus::ACTIVE->value);
    }

    /**
     * Scope: Get sessions for a specific project
     */
    public function scopeForProject(Builder $query, Project $project): Builder
    {
        return $query->where('project_id', $project->id);
    }
}
