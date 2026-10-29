<?php

namespace App\Models;

use App\Enums\QuotaFetchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single reading of Anthropic's server-reported quota.
 *
 * Failed fetches are stored too — an outage is part of the history.
 */
class QuotaSnapshot extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'fetched_at', 'status', 'account', 'reason',
        'five_hour_percent', 'seven_day_percent',
        'five_hour_resets_at', 'seven_day_resets_at',
        'payload',
        'local_window_started_at', 'local_tokens', 'local_cost_usd',
        'local_indexed_through', 'local_scan_lag_s', 'local_breakdown',
    ];

    protected $casts = [
        'status' => QuotaFetchStatus::class,
        'fetched_at' => 'datetime',
        'five_hour_resets_at' => 'datetime',
        'seven_day_resets_at' => 'datetime',
        'five_hour_percent' => 'integer',
        'seven_day_percent' => 'integer',
        'payload' => 'array',
        'local_window_started_at' => 'datetime',
        'local_indexed_through' => 'datetime',
        'local_tokens' => 'integer',
        'local_cost_usd' => 'float',
        'local_scan_lag_s' => 'integer',
        'local_breakdown' => 'array',
    ];

    /** Most recent reading of any kind, successful or not. */
    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('fetched_at');
    }

    /** Readings that actually carry numbers — for trend/history views. */
    public function scopeSuccessful($query)
    {
        return $query->where('status', QuotaFetchStatus::OK->value);
    }

    /**
     * Readings usable as calibration pairs: a real percentage AND a local count
     * that was fresh enough to mean anything.
     *
     * A stale index is not a small error, it is a missing observation — the
     * local side of the pair describes a different moment than the real side.
     */
    public function scopeCalibratable($query, int $maxLagSeconds = 300)
    {
        return $query->successful()
            ->whereNotNull('local_tokens')
            ->whereNotNull('local_scan_lag_s')
            ->where('local_scan_lag_s', '<=', $maxLagSeconds);
    }
}
