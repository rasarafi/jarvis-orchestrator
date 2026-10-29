<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContextSection;
use App\Http\Controllers\Controller;
use App\Services\ProjectContextService;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectContextController extends Controller
{
    public function __construct(
        private ProjectContextService $contextService,
        private ProjectService $projectService,
    ) {}

    /**
     * GET /api/projects/{slug}/context
     * All sections for a project. Optional ?section= filter.
     */
    public function index(string $slug, Request $request): JsonResponse
    {
        $project = $this->projectService->findBySlug($slug);

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        if ($section = $request->query('section')) {
            $enum = ContextSection::tryFrom($section);
            if (!$enum) {
                return response()->json(['message' => 'Invalid section', 'valid' => array_column(ContextSection::cases(), 'value')], 422);
            }

            $context = $this->contextService->getSection($project, $enum);
            return response()->json(['data' => $context]);
        }

        $context = $this->contextService->getProjectContext($project);
        return response()->json(['data' => $context]);
    }

    /**
     * GET /api/projects/{slug}/context/{section}
     * Single section for a project.
     */
    public function show(string $slug, string $section): JsonResponse
    {
        $project = $this->projectService->findBySlug($slug);

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $enum = ContextSection::tryFrom($section);
        if (!$enum) {
            return response()->json(['message' => 'Invalid section', 'valid' => array_column(ContextSection::cases(), 'value')], 422);
        }

        $context = $this->contextService->getSection($project, $enum);
        return response()->json(['data' => $context]);
    }

    /**
     * PUT /api/projects/{slug}/context/{section}
     * Upsert a section.
     */
    public function update(Request $request, string $slug, string $section): JsonResponse
    {
        $project = $this->projectService->findBySlug($slug);

        if (!$project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $enum = ContextSection::tryFrom($section);
        if (!$enum) {
            return response()->json(['message' => 'Invalid section', 'valid' => array_column(ContextSection::cases(), 'value')], 422);
        }

        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        $context = $this->contextService->updateSection($project, $enum, $validated['content']);

        return response()->json(['data' => $context]);
    }

    /**
     * GET /api/context/{section}
     * One section across all projects.
     */
    public function crossProject(string $section): JsonResponse
    {
        $enum = ContextSection::tryFrom($section);
        if (!$enum) {
            return response()->json(['message' => 'Invalid section', 'valid' => array_column(ContextSection::cases(), 'value')], 422);
        }

        $context = $this->contextService->getCrossProject($enum);
        return response()->json(['data' => $context]);
    }
}
