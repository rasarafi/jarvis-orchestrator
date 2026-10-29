<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProjectColorService;
use Illuminate\Http\JsonResponse;

class ProjectColorsController extends Controller
{
    public function __construct(private ProjectColorService $service) {}

    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => (object) $this->service->allColors()]);
    }
}
