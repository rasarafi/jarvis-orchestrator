<?php

namespace App\Http\Concerns;

use App\Enums\TaskActor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesTaskActor
{
    protected function resolveActor(Request $request): TaskActor
    {
        $raw = $request->header('X-Jarvis-Actor');

        if ($raw === null || $raw === '') {
            return TaskActor::USER;
        }

        return TaskActor::tryFrom($raw) ?? throw ValidationException::withMessages([
            'actor' => "Invalid X-Jarvis-Actor header: {$raw}",
        ]);
    }
}
