<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyPlan extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'date',
        'must_do',
        'should_do',
        'could_do',
        'alerts',
        'raw_output',
        'actual_outcome',
        'must_do_completed',
        'should_do_completed',
        'could_do_completed',
    ];

    protected $casts = [
        'date' => 'date',
        'alerts' => 'array',
        'must_do_completed' => 'boolean',
        'should_do_completed' => 'boolean',
        'could_do_completed' => 'boolean',
    ];
}
