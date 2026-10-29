<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\TodayController;
use App\Http\Controllers\Api\FocusSessionController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\TimeBlockController;
use App\Http\Controllers\Api\InterruptController;
use App\Http\Controllers\Api\MemoryController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ProjectContextController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectColorsController;
use App\Http\Controllers\Api\ProjectSyncController;
use App\Http\Controllers\Api\RecapController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\SweepController;
use App\Http\Controllers\Api\NoteController;
use App\Http\Controllers\Api\PingController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaskDependencyController;
use App\Http\Controllers\Api\TaskEventController;
use Illuminate\Support\Facades\Route;

// Liveness probe — DB-independent, always 200 (reports db: up|down)
Route::get('/ping', PingController::class);

// Projects
Route::get('/projects', [ProjectController::class, 'index']);
// Project colors map (used by chips across all pages)
Route::get('/projects/colors', ProjectColorsController::class);
Route::get('/projects/{slug}/overview', [ProjectController::class, 'overview']);
Route::get('/projects/{slug}', [ProjectController::class, 'show']);
Route::patch('/projects/{slug}', [ProjectController::class, 'update']);
Route::get('/projects/{slug}/context', [ProjectContextController::class, 'index']);
Route::get('/projects/{slug}/context/{section}', [ProjectContextController::class, 'show']);
Route::put('/projects/{slug}/context/{section}', [ProjectContextController::class, 'update']);
Route::get('/context/{section}', [ProjectContextController::class, 'crossProject']);
Route::post('/projects/{slug}/sync-tasks', [ProjectSyncController::class, 'syncTasks']);
Route::get('/projects/{slug}/tasks', [ProjectSyncController::class, 'exportTasks']);

// Tasks
Route::get('/notes', [NoteController::class, 'index']);
Route::post('/notes', [NoteController::class, 'store']);
Route::patch('/notes/{note}/keep', [NoteController::class, 'keep']);
Route::patch('/notes/{note}/archive', [NoteController::class, 'archive']);
Route::patch('/notes/{note}/restore', [NoteController::class, 'restore']);
Route::post('/notes/{note}/convert-to-task', [NoteController::class, 'convertToTask']);
Route::post('/notes/{note}/promote-to-knowledge', [NoteController::class, 'promoteToKnowledge']);
Route::patch('/notes/{note}', [NoteController::class, 'update']);
Route::delete('/notes/{note}', [NoteController::class, 'destroy']);

Route::get('/tasks/urgent', [TaskController::class, 'urgent']);
Route::get('/tasks/{task}/events', [TaskEventController::class, 'index']);
Route::post('/tasks/{task}/dependencies', [TaskDependencyController::class, 'store']);
Route::delete('/tasks/{task}/dependencies/{dependsOn}', [TaskDependencyController::class, 'destroy']);
Route::patch('/tasks/{task}/archive', [TaskController::class, 'archive']);
Route::patch('/tasks/{task}/unarchive', [TaskController::class, 'unarchive']);
Route::post('/tasks/sweep', [SweepController::class, 'run']);
Route::get('/tasks/sweep/latest', [SweepController::class, 'latest']);
Route::patch('/tasks/sweep/matches/{match}/confirm', [SweepController::class, 'confirm']);
Route::patch('/tasks/sweep/matches/{match}/dismiss', [SweepController::class, 'dismiss']);
Route::patch('/tasks/sweep/matches/{match}/undo', [SweepController::class, 'undo']);

// Handover audits
Route::post('/audits', [AuditController::class, 'store']);
Route::get('/audits/recent', [AuditController::class, 'recent']);
Route::get('/audits/latest', [AuditController::class, 'latest']);
Route::get('/audits/suppressions', [AuditController::class, 'suppressions']);
Route::get('/audits/features', [AuditController::class, 'features']);
Route::get('/audits', [AuditController::class, 'index']);
Route::patch('/audits/findings/{finding}/dismiss', [AuditController::class, 'dismiss']);
Route::patch('/audits/findings/{finding}/reopen', [AuditController::class, 'reopen']);
Route::post('/audits/findings/{finding}/convert-to-task', [AuditController::class, 'convertToTask']);
Route::apiResource('tasks', TaskController::class)->except(['show']);

// Plans
Route::get('/plans/today', [PlanController::class, 'today']);
Route::get('/plans/streak', [PlanController::class, 'streak']);
Route::get('/plans/recent', [PlanController::class, 'recent']);
Route::post('/plans', [PlanController::class, 'store']);
Route::patch('/plans/{plan}/outcome', [PlanController::class, 'updateOutcome']);
Route::patch('/plans/{plan}/completion', [PlanController::class, 'updateCompletion']);

// Reviews
Route::get('/reviews/current', [ReviewController::class, 'current']);
Route::post('/reviews', [ReviewController::class, 'store']);

// Analytics
Route::get('/analytics/costs', [AnalyticsController::class, 'costs']);
Route::get('/analytics/sessions', [AnalyticsController::class, 'sessions']);
Route::get('/analytics/today-sessions', [AnalyticsController::class, 'todaySessions']);
Route::get('/analytics/health', [AnalyticsController::class, 'health']);
Route::get('/analytics/quota-window', [AnalyticsController::class, 'quotaWindow']);
Route::get('/analytics/costs/window', [AnalyticsController::class, 'costWindow']);
Route::get('/analytics/costs/projects', [AnalyticsController::class, 'costProjects']);
Route::get('/analytics/costs/projects/{slug}/sessions', [AnalyticsController::class, 'costProjectSessions']);
Route::get('/analytics/costs/behaviors', [AnalyticsController::class, 'usageBehaviors']);

// Server-reported quota. POST is the only thing that calls Anthropic, and only
// when the user clicks — there is deliberately no poll.
Route::get('/analytics/quota/latest', [AnalyticsController::class, 'latestQuota']);
Route::post('/analytics/quota/refresh', [AnalyticsController::class, 'refreshQuota']);
Route::get('/analytics/quota/calibration', [AnalyticsController::class, 'quotaCalibration']);
Route::get('/analytics/resources', [AnalyticsController::class, 'resources']);
Route::get('/analytics/resources/trend', [AnalyticsController::class, 'resourcesTrend']);
Route::get('/analytics/tasks', [AnalyticsController::class, 'tasks']);

// Memory
Route::post('/memory/recall', [MemoryController::class, 'recall']);
Route::post('/memory/remember', [MemoryController::class, 'remember']);

// Interrupts
Route::get('/interrupts', [InterruptController::class, 'index']);
Route::post('/interrupts', [InterruptController::class, 'store']);
Route::patch('/interrupts/{interrupt}/resolve', [InterruptController::class, 'resolve']);

// Sessions (CC Session Manager integration)
Route::post('/sessions/scan', [SessionController::class, 'scan']);
Route::get('/sessions/live', [SessionController::class, 'live']);
Route::post('/sessions/notify', [SessionController::class, 'notify']);
Route::post('/sessions/account', [SessionController::class, 'account']);
Route::get('/sessions/recent', [SessionController::class, 'recent']);
Route::get('/sessions/search', [SessionController::class, 'search']);

// Recaps
Route::get('/recaps', [RecapController::class, 'index']);
Route::post('/recaps/run', [RecapController::class, 'run']);
Route::get('/recaps/rollups', [RecapController::class, 'rollups']);
Route::get('/recaps/threads', [RecapController::class, 'threads']);
Route::patch('/recaps/threads/{thread}/dismiss', [RecapController::class, 'dismissThread']);
Route::patch('/recaps/threads/{thread}/resolve', [RecapController::class, 'resolveThread']);
Route::post('/recaps/threads/dismiss-stale', [RecapController::class, 'dismissStale']);
Route::post('/recaps/threads/restore', [RecapController::class, 'restoreThreads']);
Route::get('/recaps/suggestions', [RecapController::class, 'suggestions']);

// Time blocks (new Today page)
Route::get('/time-blocks', [TimeBlockController::class, 'index']);
Route::post('/time-blocks', [TimeBlockController::class, 'store']);
Route::patch('/time-blocks/{timeBlock}', [TimeBlockController::class, 'update']);
Route::delete('/time-blocks/{timeBlock}', [TimeBlockController::class, 'destroy']);

// Scheduling
Route::post('/tasks/{task}/schedule', [ScheduleController::class, 'schedule']);
Route::patch('/scheduled-tasks/{scheduledTask}/reschedule', [ScheduleController::class, 'reschedule']);

// Focus sessions
Route::post('/focus-sessions', [FocusSessionController::class, 'start']);
Route::patch('/focus-sessions/{focusSession}/heartbeat', [FocusSessionController::class, 'heartbeat']);
Route::patch('/focus-sessions/{focusSession}/end', [FocusSessionController::class, 'end']);

// Today (composite read for the Today page)
Route::get('/today', TodayController::class);
