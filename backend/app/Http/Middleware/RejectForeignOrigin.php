<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API has no login: it trusts anything that can reach localhost. CORS only
 * stops a foreign page from READING responses; a "simple" cross-site POST from
 * any website the user has open still executes. Browsers always attach Origin to
 * those requests, so reject any Origin the deck didn't come from. Requests with
 * no Origin (curl, Claude Code hooks, the scheduler) are unaffected.
 */
class RejectForeignOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null && ! in_array($origin, config('cors.allowed_origins', []), true)) {
            return response()->json(['message' => 'Origin not allowed.'], 403);
        }

        return $next($request);
    }
}
