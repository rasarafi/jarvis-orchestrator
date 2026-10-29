<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lightweight liveness probe. Unlike every other endpoint, this NEVER lets a DB
 * failure bubble up to a 500 — it catches the connection error and reports
 * db: 'down' with a 200. That makes it the one reliable signal the frontend can
 * poll to detect a Postgres outage (Docker DB-postgres down) and warn the user,
 * instead of showing eight mystery "failed to load" panels.
 */
class PingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $dbUp = false;

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $dbUp = true;
        } catch (Throwable) {
            $dbUp = false;
        }

        return response()->json([
            'ok' => true,
            'db' => $dbUp ? 'up' : 'down',
        ]);
    }
}
