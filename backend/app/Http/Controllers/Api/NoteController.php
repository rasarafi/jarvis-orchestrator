<?php

namespace App\Http\Controllers\Api;

use App\Enums\NoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Services\NoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function __construct(
        private NoteService $noteService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'nullable|string|in:inbox,kept,archived',
            'project' => 'nullable|uuid',
        ]);

        $status = NoteStatus::from($validated['status'] ?? NoteStatus::INBOX->value);

        $query = Note::with('project')->where('status', $status->value);

        if (!empty($validated['project'])) {
            $query->where('project_id', $validated['project']);
        }

        $query = match ($status) {
            NoteStatus::INBOX => $query->oldest(),
            NoteStatus::KEPT => $query->orderByDesc('pinned')->latest(),
            NoteStatus::ARCHIVED => $query->latest(),
        };

        return response()->json([
            'data' => $query->get(),
            'meta' => ['counts' => $this->noteService->counts()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => 'required|string',
            'project_id' => 'nullable|uuid|exists:projects,id',
            'source' => 'nullable|string|in:remote,deck,chat',
        ]);

        $note = $this->noteService->capture($validated);

        return response()->json(['data' => $note->load('project')], 201);
    }

    public function update(Request $request, Note $note): JsonResponse
    {
        $validated = $request->validate([
            'body' => 'nullable|string',
            'project_id' => 'nullable|uuid|exists:projects,id',
            'pinned' => 'nullable|boolean',
        ]);

        $note = $this->noteService->update($note, $validated);

        return response()->json(['data' => $note->load('project')]);
    }

    public function keep(Note $note): JsonResponse
    {
        return response()->json(['data' => $this->noteService->keep($note)->load('project')]);
    }

    public function archive(Note $note): JsonResponse
    {
        return response()->json(['data' => $this->noteService->archive($note)->load('project')]);
    }

    public function restore(Note $note): JsonResponse
    {
        return response()->json(['data' => $this->noteService->restore($note)->load('project')]);
    }

    public function convertToTask(Request $request, Note $note): JsonResponse
    {
        $validated = $request->validate([
            'priority' => 'nullable|string|in:urgent,high,medium,low',
            'project_id' => 'nullable|uuid|exists:projects,id',
            'title' => 'nullable|string|max:255',
        ]);

        $task = $this->noteService->convertToTask($note, $validated);

        return response()->json(['data' => $task->load('project'), 'note_id' => $note->id], 201);
    }

    public function promoteToKnowledge(Request $request, Note $note): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string|in:fix,decision,pattern,gotcha',
            'tags' => 'nullable|array',
        ]);

        $knowledge = $this->noteService->promoteToKnowledge($note, $validated);

        return response()->json(['data' => $knowledge, 'note_id' => $note->id], 201);
    }

    public function destroy(Note $note): JsonResponse
    {
        $note->delete();

        return response()->json(['message' => 'Note deleted']);
    }
}
