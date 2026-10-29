<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CcSession extends Model
{
    // Schema-qualified to avoid collision with any public schema table
    protected $table = 'sessions.sessions';

    // Read-only: JARVIS should never write to CC Session Manager tables
    protected $guarded = ['*'];

    // CC Session Manager uses serial (integer) PKs, not UUIDs
    public $incrementing = true;
    protected $keyType = 'int';

    // Table has indexed_at, not created_at/updated_at
    public $timestamps = false;

    protected $casts = [
        'first_prompt_timestamp' => 'datetime',
        'last_activity_timestamp' => 'datetime',
        'indexed_at' => 'datetime',
        'estimated_cost_usd' => 'decimal:4',
        'message_count' => 'integer',
        'turn_count' => 'integer',
        'total_input_tokens' => 'integer',
        'total_output_tokens' => 'integer',
        'total_cache_creation_tokens' => 'integer',
        'total_cache_read_tokens' => 'integer',
        'has_subagents' => 'boolean',
        'is_starred' => 'boolean',
        'is_archived' => 'boolean',
        'fork_count' => 'integer',
    ];

    public function ccProject(): BelongsTo
    {
        return $this->belongsTo(CcProject::class, 'project_id');
    }

    // --- Scopes ---

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('first_prompt_timestamp', today());
    }

    public function scopeThisWeek(Builder $query): Builder
    {
        return $query->where('first_prompt_timestamp', '>=', now()->startOfWeek());
    }

    public function scopeForCcProject(Builder $query, int|array $ccProjectIds): Builder
    {
        return $query->whereIn('project_id', (array) $ccProjectIds);
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('first_prompt_timestamp', $date);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('first_prompt_timestamp', [$from, $to]);
    }

    public function scopeLatestActivity(Builder $query): Builder
    {
        return $query->orderByDesc('last_activity_timestamp');
    }

    /**
     * Only real sessions — excludes subagent transcripts, which are indexed as
     * their own rows (kind = 'subagent') so their tokens are counted, but are
     * not sessions the user ever started. Use wherever sessions are LISTED or
     * COUNTED; leave it off when summing tokens or cost.
     */
    public function scopeMainOnly(Builder $query): Builder
    {
        return $query->where('kind', 'main');
    }

    /**
     * Filter out trivial sessions (/clear, /model with ≤3 messages and $0 cost)
     */
    public function scopeNonTrivial(Builder $query): Builder
    {
        return $query->where('message_count', '>', 3)
                     ->where('estimated_cost_usd', '>', 0);
    }
}
