<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MemoryController extends Controller
{
    public function __construct(
        private MemoryService $memoryService,
    ) {}

    public function recall(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|max:500',
            'top_k' => 'nullable|integer|min:1|max:20',
            'project_slug' => 'nullable|string',
        ]);

        $filter = null;
        if (!empty($validated['project_slug'])) {
            $filter = ['project_slug' => ['$eq' => $validated['project_slug']]];
        }

        $results = $this->memoryService->recall(
            $validated['query'],
            $validated['top_k'] ?? 5,
            $filter,
        );

        return response()->json(['data' => $results]);
    }

    public function remember(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'type' => 'nullable|string',
            'project_slug' => 'nullable|string',
        ]);

        $id = 'manual-' . Str::uuid();

        $this->memoryService->index($id, $validated['content'], [
            'project_slug' => $validated['project_slug'] ?? '',
            'source' => 'manual',
            'type' => $validated['type'] ?? 'insight',
            'created_at' => now()->toDateString(),
        ]);

        return response()->json(['message' => 'Memory stored']);
    }
}
