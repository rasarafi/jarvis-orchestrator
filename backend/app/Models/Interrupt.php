<?php

namespace App\Models;

use App\Enums\InterruptStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interrupt extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'project_id',
        'was_working_on',
        'progress',
        'resume_instructions',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'status' => InterruptStatus::class,
        'resolved_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
