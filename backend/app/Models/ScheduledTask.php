<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledTask extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'scheduled_tasks';

    protected $fillable = [
        'task_id',
        'time_block_id',
        'scheduled_date',
        'start_time',
        'end_time',
        'position',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'position' => 'integer',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function timeBlock(): BelongsTo
    {
        return $this->belongsTo(TimeBlock::class);
    }

    /**
     * Scope: tasks scheduled on a given date.
     * SINGLE SOURCE OF TRUTH for "tasks on day X" filter.
     */
    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('scheduled_date', $date);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('start_time')->orderBy('position');
    }
}
