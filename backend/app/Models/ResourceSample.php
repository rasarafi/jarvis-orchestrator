<?php

namespace App\Models;

use App\Enums\ResourcePressure;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ResourceSample extends Model
{
    use HasUuids;

    protected $fillable = [
        'cpu_load', 'cores',
        'mem_used_mb', 'mem_total_mb',
        'swap_used_mb', 'swap_total_mb',
        'session_count', 'pressure', 'sampled_at',
        'host_mem_pct', 'host_commit_pct', 'host_pressure',
    ];

    protected $casts = [
        'cpu_load' => 'float',
        'cores' => 'integer',
        'mem_used_mb' => 'integer',
        'mem_total_mb' => 'integer',
        'swap_used_mb' => 'integer',
        'swap_total_mb' => 'integer',
        'session_count' => 'integer',
        'pressure' => ResourcePressure::class,
        'sampled_at' => 'datetime',
        'host_mem_pct' => 'float',
        'host_commit_pct' => 'float',
        'host_pressure' => ResourcePressure::class,
    ];
}
