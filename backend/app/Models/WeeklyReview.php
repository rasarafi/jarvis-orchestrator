<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeeklyReview extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'week_start',
        'summary',
        'client_digests',
        'behavior_insights',
        'next_week_plan',
    ];

    protected $casts = [
        'week_start' => 'date',
        'client_digests' => 'array',
        'behavior_insights' => 'array',
    ];
}
