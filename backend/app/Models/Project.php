<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Project extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'slug',
        'name',
        'purpose',
        'stack',
        'repo_url',
        'local_path',
        'assistant_data_path',
        'status',
        'group_key',
        'current_focus',
        'blockers',
        'last_session_at',
        'key_modules',
        'known_risks',
        'environments',
    ];

    protected $casts = [
        'stack' => 'array',
        'last_session_at' => 'datetime',
        'key_modules' => 'array',
        'environments' => 'array',
        'ports' => 'array',
        'run_commands' => 'array',
        'group_key' => \App\Enums\ProjectGroup::class,
    ];

    /**
     * True when $path is this project's local_path or a subdirectory of it.
     */
    public function ownsPath(?string $path): bool
    {
        if (! $path || ! $this->local_path) {
            return false;
        }

        return str_starts_with(rtrim($path, '/').'/', rtrim($this->local_path, '/').'/');
    }

    /**
     * SSOT for session↔project path matching: resolve a filesystem path
     * (session cwd, CC decoded_path) to the owning project. Longest
     * local_path prefix wins, so nested project roots resolve correctly.
     */
    public static function resolveByPath(?string $path): ?self
    {
        return static::resolveFromCollection(
            static::query()->whereNotNull('local_path')->get(),
            $path
        );
    }

    /**
     * Same longest-prefix rule against an already-loaded project collection —
     * for callers enriching many rows without a query per row.
     */
    public static function resolveFromCollection(Collection $projects, ?string $path): ?self
    {
        if (! $path) {
            return null;
        }

        return $projects
            ->sortByDesc(fn (self $p) => strlen((string) $p->local_path))
            ->first(fn (self $p) => $p->ownsPath($path));
    }

    public function scopeDormant(Builder $query, int $days = 10): Builder
    {
        return $query->where('status', 'active')
            ->whereNotNull('last_session_at')
            ->where('last_session_at', '<', now()->subDays($days));
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkSession::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function knowledge(): HasMany
    {
        return $this->hasMany(Knowledge::class);
    }

    public function interrupts(): HasMany
    {
        return $this->hasMany(Interrupt::class);
    }

    public function context(): HasMany
    {
        return $this->hasMany(ProjectContext::class);
    }
}
