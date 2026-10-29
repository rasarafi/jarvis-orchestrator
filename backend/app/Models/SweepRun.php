<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SweepRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'status',
        'scope',
        'window_days',
        'model',
        'stats',
        'fingerprints',
    ];

    protected $casts = [
        'scope' => 'array',
        'stats' => 'array',
        'fingerprints' => 'array',
        'window_days' => 'integer',
    ];

    public function matches(): HasMany
    {
        return $this->hasMany(SweepMatch::class);
    }
}
