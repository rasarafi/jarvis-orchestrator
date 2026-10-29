<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CcSession;
use App\Models\Project;
use App\Services\LiveSessionService;
use App\Services\SessionAccountService;
use App\Services\SessionAttentionService;
use App\Services\SessionScannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function scan(Request $request, SessionScannerService $scanner): JsonResponse
    {
        $projectDir = $request->input('project');

        try {
            $result = $projectDir
                ? $scanner->scanProject($projectDir)
                : $scanner->scanAll();

            return response()->json([
                'status' => 'completed',
                ...$result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function live(LiveSessionService $service): JsonResponse
    {
        return response()->json($service->live());
    }

    /**
     * Receives Claude Code Notification-hook payloads (hook stdin piped via curl).
     * Extra hook fields (hook_event_name, transcript_path, …) are ignored.
     */
    public function notify(Request $request, SessionAttentionService $attention): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:1000'],
            'cwd' => ['nullable', 'string', 'max:500'],
        ]);

        $attention->flag($data['session_id'], $data['message'] ?? null, $data['cwd'] ?? null);

        return response()->json(['ok' => true]);
    }

    /**
     * Receives SessionStart-hook payloads that label a session with the Claude
     * account it was started under (see tools/session-account-hook/).
     *
     * Signaling only — no transcript data. Two accounts share one projects/
     * tree via a symlink and nothing in the .jsonl identifies the account, so
     * this is the only moment attribution can be captured.
     */
    public function account(Request $request, SessionAccountService $accounts): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:100'],
            'account' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $accounts->record($data['session_id'], $data['account'], $data['email'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function recent(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 20);

        $sessions = CcSession::query()
            ->mainOnly()
            ->with('ccProject')
            // NULLS LAST: imported archive rows can lack an activity timestamp,
            // and Postgres sorts NULLs first on DESC — they would hijack "recent".
            ->orderByRaw('last_activity_timestamp DESC NULLS LAST')
            ->limit($limit)
            ->get();

        // Enrich with the matching Jarvis project (decoded_path <-> local_path)
        // so clients can filter by project group.
        $jarvisProjects = Project::query()
            ->whereNotNull('local_path')
            ->get(['slug', 'group_key', 'local_path']);

        $data = $sessions->map(function ($session) use ($jarvisProjects) {
            $jarvis = Project::resolveFromCollection($jarvisProjects, $session->ccProject?->decoded_path);

            return array_merge($session->toArray(), [
                'jarvis_project' => $jarvis
                    ? ['slug' => $jarvis->slug, 'group_key' => $jarvis->group_key]
                    : null,
            ]);
        });

        return response()->json(['data' => $data]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $limit = $request->input('limit', 20);

        if (empty($query)) {
            return response()->json(['data' => []]);
        }

        try {
            $results = DB::connection('pgsql')
                ->table('sessions.messages')
                ->select('session_id', 'content_text', 'timestamp', 'message_type')
                ->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$query])
                ->orderByDesc('timestamp')
                ->limit($limit)
                ->get();

            return response()->json(['data' => $results]);
        } catch (\Throwable $e) {
            // sessions.messages table may not exist (e.g., in test DB)
            return response()->json(['data' => []]);
        }
    }
}
