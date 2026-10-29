<?php

use Illuminate\Support\Facades\Schedule;

// Every five minutes, not every two hours. The index is what every cost, quota
// and live-session figure reads from, and a two-hour cadence meant those numbers
// described a moment up to two hours old (measured 2026-08-19: median gap 939s,
// max 7200s, while real quota readings moved in real time). It also made quota
// calibration pairs unusable.
// The scan is incremental (mtime-based, ~20-40s over ~3.5k files), and
// withoutOverlapping means a slow run defers the next rather than stacking.
Schedule::command('jarvis:scan-sessions')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('scheduled jarvis:scan-sessions failed'));

Schedule::command('jarvis:sample-resources')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('scheduled jarvis:sample-resources failed'));

Schedule::command('jarvis:run-recaps')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn () => config('services.claude.recap_auto_run', true))
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('scheduled jarvis:run-recaps failed'));

Schedule::command('jarvis:sync-remote')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn () => \Illuminate\Support\Facades\Log::error('scheduled jarvis:sync-remote failed'));
