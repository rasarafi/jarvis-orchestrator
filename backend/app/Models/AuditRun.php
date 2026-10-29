<?php

namespace App\Models;

use App\Enums\AuditRunStatus;
use App\Enums\AuditScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id',
        'scope',
        'feature',
        'status',
        'gate_reached',
        'app_classification',
        'summary',
        'meta',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'scope' => AuditScope::class,
        'status' => AuditRunStatus::class,
        'app_classification' => 'array',
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AuditFinding::class)->orderByRaw(
            "case severity when 'critical' then 0 when 'warning' then 1 else 2 end"
        );
    }
}
