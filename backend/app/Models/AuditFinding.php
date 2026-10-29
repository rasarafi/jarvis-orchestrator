<?php

namespace App\Models;

use App\Enums\AuditFindingSeverity;
use App\Enums\AuditFindingStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditFinding extends Model
{
    use HasUuids;

    protected $fillable = [
        'audit_run_id',
        'project_id',
        'gate',
        'module',
        'severity',
        'title',
        'detail',
        'evidence',
        'status',
        'suppression_key',
        'dismissed_at',
        'task_id',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected $casts = [
        'severity' => AuditFindingSeverity::class,
        'status' => AuditFindingStatus::class,
        'evidence' => 'array',
        'dismissed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AuditRun::class, 'audit_run_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
