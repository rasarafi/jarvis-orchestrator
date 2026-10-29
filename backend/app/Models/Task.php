<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskSource;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * scheduled_tasks is not a synced table, so hard-deleting its rows here
     * is correct. The FK's cascadeOnDelete() never fires on a soft delete
     * (it's an UPDATE, not a DELETE), so we clean up explicitly on both
     * soft and force deletes.
     */
    protected static function booted(): void
    {
        static::deleting(function (Task $task) {
            $task->scheduledTasks()->delete();
        });
    }

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'status',
        'priority',
        'deadline',
        'blocker_reason',
        'completed_at',
        'source',
        'parent_task_id',
        'start_date',
        'estimate_minutes',
        'archived_at',
    ];

    protected $casts = [
        'deadline' => 'date',
        'completed_at' => 'datetime',
        'source' => TaskSource::class,
        'start_date' => 'date',
        'archived_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scheduledTasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'depends_on_task_id', 'task_id');
    }

    /**
     * Scope: exclude archived tasks
     * SINGLE SOURCE OF TRUTH for the archive filter
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Scope: Get only open tasks (todo, in_progress, blocked)
     * SINGLE SOURCE OF TRUTH for "open task" filter
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', TaskStatus::openStatuses());
    }

    /**
     * Scope: Order tasks by priority (urgent -> low)
     * SINGLE SOURCE OF TRUTH for priority ordering
     */
    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderByRaw(TaskPriority::orderingCase());
    }

    /**
     * Scope: Get urgent tasks (deadline within 3 days and in future)
     */
    public function scopeUrgent(Builder $query): Builder
    {
        return $query->whereNotNull('deadline')
            ->where('deadline', '>', now())
            ->where('deadline', '<=', now()->addDays(3));
    }

    /**
     * Scope: Get stale tasks (created more than 30 days ago)
     */
    public function scopeStale(Builder $query): Builder
    {
        return $query->where('created_at', '<', now()->subDays(30));
    }
}
