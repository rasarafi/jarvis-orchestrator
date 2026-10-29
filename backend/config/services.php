<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'claude' => [
        // Where Claude Code writes session transcripts (one dir per project).
        'projects_dir' => env('CLAUDE_PROJECTS_DIR', ($_SERVER['HOME'] ?? '/home/dev') . '/.claude/projects'),
        'binary' => env('RECAP_CLAUDE_BIN', 'claude'),
        'timeout' => (int) env('RECAP_CLAUDE_TIMEOUT', 180),
        'recap_config_dir' => env('RECAP_CLAUDE_CONFIG_DIR'), // optional: pin which CC account runs recaps
        // Lean mode strips setting sources (MCP/skills/CLAUDE.md/hooks) + caps to one tool-less
        // turn — a summarizer needs none of the agent bootstrap and it runs ~5x faster.
        'lean' => (bool) env('RECAP_CLAUDE_LEAN', true),
        // How many sessions a single recap run summarizes concurrently (one claude -p each).
        // The box is 16-core and the work is inference-bound, so this is gated by Claude
        // rate limits, not local CPU. Dial down via env if you hit rate limits.
        'concurrency' => (int) env('RECAP_CONCURRENCY', 8),
        // Scheduled recap auto-run (jarvis:run-recaps every 15 min). Set false to go
        // back to manual-only summarization via the Run button.
        'recap_auto_run' => (bool) env('JARVIS_RECAP_AUTO_RUN', true),
    ],

    'jarvis_remote' => [
        'url' => env('JARVIS_REMOTE_URL', 'https://jarvis-remote.example.com'),
        'sync_key' => env('JARVIS_REMOTE_SYNC_KEY'),
        'auto_push' => env('JARVIS_REMOTE_AUTO_PUSH', true),
    ],

    // Server-reported quota, fetched from Anthropic with the OAuth token Claude
    // Code stores on disk. Manual-fetch only — there is deliberately no poller.
    'claude_usage' => [
        // A second account keeps its own credentials file; the two accounts have
        // separate quota, so per-account fetch is a path this leaves open.
        'credentials_path' => env(
            'JARVIS_CLAUDE_CREDENTIALS_PATH',
            ($_SERVER['HOME'] ?? '/home/dev') . '/.claude/.credentials.json',
        ),
        'timeout' => (int) env('JARVIS_CLAUDE_USAGE_TIMEOUT', 10),
    ],

    // Windows-host vitals, published by the tray agent in tools/host-metrics-agent.
    // /proc only sees inside the WSL VM, so without this the hang verdict is blind
    // to the half of the machine Windows is using.
    'host_metrics' => [
        'path' => env('JARVIS_HOST_METRICS_PATH', '/mnt/c/Comm/Jarvis-HostMetrics/host.json'),
        // Older than this and we treat the agent as down rather than serving frozen numbers.
        'stale_after' => (int) env('JARVIS_HOST_METRICS_STALE_AFTER', 30),
    ],

];
