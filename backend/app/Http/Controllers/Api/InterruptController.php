<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Interrupt;
use App\Models\Project;
use App\Services\InterruptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InterruptController extends Controller
{
    public function __construct(
        private InterruptService $interruptService,
    ) {}

    public function index(): JsonResponse
    {
        $interrupts = $this->interruptService->getActiveInterrupts();

        return response()->json(['data' => $interrupts]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'was_working_on' => 'required|string',
            'progress' => 'nullable|string',
            'resume_instructions' => 'nullable|string',
        ]);

        $project = Project::findOrFail($validated['project_id']);
        $interrupt = $this->interruptService->createInterrupt($project, $validated);

        return response()->json(['data' => $interrupt->load('project')], 201);
    }

    public function resolve(Interrupt $interrupt): JsonResponse
    {
        $interrupt = $this->interruptService->resolveInterrupt($interrupt);

        return response()->json(['data' => $interrupt]);
    }
}
