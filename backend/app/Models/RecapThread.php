<?php // backend/app/Models/RecapThread.php
namespace App\Models;

use App\Enums\RecapThreadStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecapThread extends Model
{
    use HasUuids;

    /** Open threads older than this are considered stale and hidden from the default view. */
    public const STALE_DAYS = 14;

    protected $guarded = [];

    protected $casts = [
        'status' => RecapThreadStatus::class,
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sourceRecap(): BelongsTo
    {
        return $this->belongsTo(SessionRecap::class, 'source_recap_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', RecapThreadStatus::OPEN);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->open()->where('created_at', '>=', now()->subDays(self::STALE_DAYS));
    }

    public function scopeStale(Builder $query): Builder
    {
        return $query->open()->where('created_at', '<', now()->subDays(self::STALE_DAYS));
    }
}
