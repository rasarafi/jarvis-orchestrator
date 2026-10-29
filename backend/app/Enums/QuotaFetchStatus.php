<?php

namespace App\Enums;

/**
 * Outcome of one fetch against Anthropic's usage endpoint.
 *
 * Every failure is a first-class state rather than an exception: the endpoint is
 * undocumented and can change or disappear without notice, and the failure mode
 * to avoid is rendering a frozen number as if it were live.
 */
enum QuotaFetchStatus: string
{
    /** 200 with a payload we could read. */
    case OK = 'ok';

    /** The CLI's OAuth token is past `expiresAt`, or the endpoint rejected it. */
    case AUTH_EXPIRED = 'auth_expired';

    /** No credentials file, network failure, timeout, or a non-200 response. */
    case UNREACHABLE = 'unreachable';

    /** 200, but the body was not JSON or carried no `limits` we could read. */
    case MALFORMED = 'malformed';

    public function isOk(): bool
    {
        return $this === self::OK;
    }

    /** Short human explanation, used when the service has nothing more specific. */
    public function label(): string
    {
        return match ($this) {
            self::OK => 'Fetched.',
            self::AUTH_EXPIRED => 'Claude Code\'s OAuth token has expired — run any `claude` command to refresh it.',
            self::UNREACHABLE => 'Could not reach the usage endpoint.',
            self::MALFORMED => 'The usage endpoint returned an unexpected response.',
        };
    }
}
