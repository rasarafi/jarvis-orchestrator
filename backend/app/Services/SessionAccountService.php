<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which Claude account (cl / cl2) a session belongs to.
 *
 * Two subscriptions share one `~/.claude/projects` tree through a symlink and
 * nothing in the .jsonl identifies the account, so attribution can only be
 * captured live: the SessionStart hook (tools/session-account-hook/) POSTs
 * session_id + account, `record()` stores it, and the scanner stamps
 * `sessions.sessions.account` at index time.
 *
 * Everything indexed before the hook existed stays null and is reported as
 * `unattributed` — there is no signal to backfill it from. Merged behaviour is
 * unaffected throughout: account is a filter, never a partition, and a null
 * filter means "all accounts" everywhere.
 */
class SessionAccountService
{
    /** The account keys the API accepts. `unattributed` selects account IS NULL. */
    public const KEYS = ['cl', 'cl2', 'unattributed'];

    public const UNATTRIBUTED = 'unattributed';

    /**
     * Store (or refresh) the mapping for a session, and stamp the session row
     * if the scanner has already indexed it — otherwise attribution would wait
     * for the next reindex of that file.
     */
    public function record(string $sessionId, string $account, ?string $email = null): void
    {
        DB::table('sessions.session_accounts')->upsert(
            [[
                'session_id' => $sessionId,
                'account' => $account,
                'email' => $email,
                'first_seen_at' => now(),
            ]],
            ['session_id'],
            ['account', 'email'],
        );

        // Covers the session row itself and any subagent rows hanging off it.
        DB::table('sessions.sessions')
            ->where('session_id', $sessionId)
            ->orWhere('parent_session_id', $sessionId)
            ->update(['account' => $account]);
    }

    /**
     * Accounts that actually appear in the index, heaviest first, so the UI can
     * offer a filter without hardcoding account keys.
     *
     * @return array<int, array{key: string, sessions: int}>
     */
    public function present(): array
    {
        $rows = DB::table('sessions.sessions')
            ->where('kind', 'main')
            // Literal, not a binding: a bound value in both GROUP BY and SELECT
            // binds in the wrong order and Postgres rejects the grouping.
            ->groupByRaw("coalesce(account, '".self::UNATTRIBUTED."')")
            ->selectRaw("coalesce(account, '".self::UNATTRIBUTED."') as key, count(*) as sessions")
            ->orderByDesc('sessions')
            ->get();

        return $rows->map(fn ($r) => [
            'key' => (string) $r->key,
            'sessions' => (int) $r->sessions,
        ])->all();
    }

    /**
     * Constrain a query on `sessions.sessions` to one account.
     *
     * @param  string  $alias  the table alias used in the query
     */
    public function constrainSessions(Builder $query, ?string $account, string $alias = 'sessions.sessions'): Builder
    {
        if ($account === null) {
            return $query;
        }

        $column = $this->column($alias);

        return $account === self::UNATTRIBUTED
            ? $query->whereNull($column)
            : $query->where($column, $account);
    }

    /**
     * Constrain a query on `sessions.messages` to one account, by existence of
     * the owning session row. A join would risk fanning rows out; EXISTS cannot.
     *
     * @param  string  $sessionIdColumn  qualified session_id column in the outer query
     */
    public function constrainMessages(Builder $query, ?string $account, string $sessionIdColumn): Builder
    {
        if ($account === null) {
            return $query;
        }

        return $query->whereExists(function (Builder $sub) use ($account, $sessionIdColumn) {
            $sub->from('sessions.sessions as account_owner')
                ->whereColumn('account_owner.session_id', '=', $sessionIdColumn);

            $account === self::UNATTRIBUTED
                ? $sub->whereNull('account_owner.account')
                : $sub->where('account_owner.account', $account);
        });
    }

    /** Qualify the account column for an aliased or bare table reference. */
    private function column(string $alias): string
    {
        return $alias === 'sessions.sessions' ? 'account' : $alias . '.account';
    }
}
