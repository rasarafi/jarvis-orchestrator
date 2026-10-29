<?php

namespace App\Models;

use App\Enums\TimeBlockKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeBlock extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'time_blocks';

    protected $fillable = [
        'name',
        'kind',
        'start_time',
        'end_time',
        'weekday_mask',
        'color_hint',
        'display_order',
    ];

    protected $casts = [
        'kind' => TimeBlockKind::class,
        'weekday_mask' => 'integer',
        'display_order' => 'integer',
    ];

    /**
     * Scope: only blocks active on the given weekday (0=Sunday, 6=Saturday).
     * SINGLE SOURCE OF TRUTH for weekday filtering.
     */
    public function scopeForWeekday(Builder $query, int $weekday): Builder
    {
        $bit = 1 << $weekday;
        return $query->whereRaw('(weekday_mask & ?) <> 0', [$bit]);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('start_time');
    }
}
