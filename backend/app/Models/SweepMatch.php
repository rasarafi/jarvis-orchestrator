<?php

namespace App\Models;

use App\Enums\SweepConfidence;
use App\Enums\SweepMatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SweepMatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'sweep_run_id',
        'task_id',
        'project_id',
        'commits',
        'confidence',
        'rationale',
        'status',
        'meta',
        'resolved_at',
    ];

    protected $casts = [
        'commits' => 'array',
        'meta' => 'array',
        'confidence' => SweepConfidence::class,
        'status' => SweepMatchStatus::class,
        'resolved_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SweepRun::class, 'sweep_run_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
