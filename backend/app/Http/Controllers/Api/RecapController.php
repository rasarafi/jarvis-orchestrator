<?php // backend/app/Http/Controllers/Api/RecapController.php
namespace App\Http\Controllers\Api;

use App\Enums\RecapModel;
use App\Enums\RecapThreadStatus;
use App\Http\Controllers\Controller;
use App\Models\RecapThread;
use App\Services\RecapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RecapController extends Controller
{
    public function __construct(private RecapService $recapService) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['range' => ['nullable', Rule::in(['24h', 'week'])]]);

        return response()->json(['data' => $this->recapService->overview($validated['range'] ?? '24h')]);
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'model' => ['nullable', Rule::enum(RecapModel::class)],
            'session_id' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json(['data' => $this->recapService->run(
            isset($validated['model']) ? RecapModel::from($validated['model']) : RecapModel::HAIKU,
            $validated['limit'] ?? null,
            $validated['session_id'] ?? null,
        )]);
    }

    public function rollups(Request $request): JsonResponse
    {
        $validated = $request->validate(['range' => ['nullable', Rule::in(['24h', 'week'])]]);

        return response()->json(['data' => $this->recapService->rollups($validated['range'] ?? '24h')]);
    }

    public function threads(): JsonResponse
    {
        return response()->json(['data' => $this->recapService->openThreads()]);
    }

    public function dismissThread(RecapThread $thread): JsonResponse
    {
        $thread->update(['status' => RecapThreadStatus::DISMISSED]);

        return response()->json(['data' => $thread->fresh()]);
    }

    public function resolveThread(RecapThread $thread): JsonResponse
    {
        $thread->update(['status' => RecapThreadStatus::RESOLVED]);

        return response()->json(['data' => $thread->fresh()]);
    }

    public function dismissStale(Request $request): JsonResponse
    {
        $validated = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:90']]);

        return response()->json(['data' => $this->recapService->dismissStaleThreads($validated['days'] ?? null)]);
    }

    public function restoreThreads(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['uuid'],
        ]);

        return response()->json(['data' => ['restored' => $this->recapService->restoreThreads($validated['ids'])]]);
    }

    public function suggestions(): JsonResponse
    {
        return response()->json(['data' => $this->recapService->suggestions()]);
    }
}
