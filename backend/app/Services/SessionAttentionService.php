<?php

namespace App\Services;

use App\Enums\AttentionKind;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Needs-attention flags pushed by Claude Code's Notification hook via
 * POST /api/sessions/notify. Stored in cache (no DB table: flags are
 * ephemeral), merged into the live-session payload by LiveSessionService,
 * and cleared once a session shows fresh activity (the human responded).
 */
class SessionAttentionService
{
    private const KEY = 'session_attention_flags';

    /** Well past the 30-min live window; stale flags age out on their own. */
    private const TTL_MINUTES = 240;

    /** Guards the get-modify-put mutation paths against concurrent writers. */
    private const LOCK_KEY = 'session_attention_flags:lock';

    public function flag(string $sessionId, ?string $message, ?string $cwd): void
    {
        Cache::lock(self::LOCK_KEY, 5)->block(3, function () use ($sessionId, $message, $cwd) {
            $flags = $this->all();
            $flags[$sessionId] = [
                'kind' => str_contains(mb_strtolower($message ?? ''), 'permission')
                    ? AttentionKind::PERMISSION->value
                    : AttentionKind::IDLE->value,
                'message' => $message,
                'cwd' => $cwd,
                'flagged_at' => now()->toIso8601String(),
            ];
            $this->put($flags);
        });
    }

    /**
     * @return array<string, array{kind: string, message: ?string, cwd: ?string, flagged_at: string}>
     */
    public function all(): array
    {
        return Cache::get(self::KEY, []);
    }

    public function clear(string $sessionId): void
    {
        Cache::lock(self::LOCK_KEY, 5)->block(3, function () use ($sessionId) {
            $flags = $this->all();
            if (isset($flags[$sessionId])) {
                unset($flags[$sessionId]);
                $this->put($flags);
            }
        });
    }

    /**
     * Drop flags whose session is no longer live and whose flag is older than
     * $maxAgeMinutes. A flag for a session that vanished from the live window
     * only recently might just be a transient scan gap, so it's kept until it
     * ages past the window.
     *
     * @param string[] $liveSessionIds
     */
    public function pruneAbsent(array $liveSessionIds, int $maxAgeMinutes): void
    {
        Cache::lock(self::LOCK_KEY, 5)->block(3, function () use ($liveSessionIds, $maxAgeMinutes) {
            $flags = $this->all();
            $liveIds = array_flip($liveSessionIds);
            $cutoff = now()->subMinutes($maxAgeMinutes);
            $changed = false;

            foreach ($flags as $sessionId => $flag) {
                if (isset($liveIds[$sessionId])) {
                    continue;
                }

                $flaggedAt = $flag['flagged_at'] ?? null;
                if ($flaggedAt !== null && Carbon::parse($flaggedAt)->lt($cutoff)) {
                    unset($flags[$sessionId]);
                    $changed = true;
                }
            }

            if ($changed) {
                $this->put($flags);
            }
        });
    }

    private function put(array $flags): void
    {
        Cache::put(self::KEY, $flags, now()->addMinutes(self::TTL_MINUTES));
    }
}
