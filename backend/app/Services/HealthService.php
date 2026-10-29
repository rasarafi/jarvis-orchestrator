<?php

namespace App\Services;

use App\Models\DailyPlan;
use App\Models\Task;
use App\Models\WorkSession;
use Carbon\Carbon;

class HealthService
{
    /**
     * Get behavioral insights for the current week.
     * SINGLE SOURCE OF TRUTH for weekly behavioral analysis.
     *
     * @return array<string> Array of insight strings
     */
    public function getWeeklyInsights(): array
    {
        $insights = [];
        $weekAgo = Carbon::now()->subDays(7);

        // Must Do / Should Do / Could Do completion rates
        $plans = DailyPlan::where('date', '>=', $weekAgo)->get();
        if ($plans->count() > 0) {
            $mustRate = $plans->where('must_do_completed', true)->count();
            $shouldRate = $plans->where('should_do_completed', true)->count();
            $couldRate = $plans->where('could_do_completed', true)->count();
            $total = $plans->count();

            $mustPct = round(($mustRate / $total) * 100);
            $insights[] = "Must Do completion: {$mustRate}/{$total} days ({$mustPct}%)";
            $insights[] = "Should Do completion: {$shouldRate}/{$total} days (" . round(($shouldRate / $total) * 100) . "%)";
            $insights[] = "Could Do completion: {$couldRate}/{$total} days (" . round(($couldRate / $total) * 100) . "%)";
        }

        // Task throughput
        $completed = Task::where('completed_at', '>=', $weekAgo)->count();
        $created = Task::where('created_at', '>=', $weekAgo)->count();
        $net = $completed - $created;
        $netStr = $net >= 0 ? "+{$net}" : (string) $net;
        $insights[] = "Task throughput: {$completed} completed, {$created} created ({$netStr} net)";

        // Average task age at completion
        $avgAge = Task::where('completed_at', '>=', $weekAgo)
            ->whereNotNull('completed_at')
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (completed_at - created_at)) / 86400) as avg_days')
            ->value('avg_days');

        if ($avgAge !== null) {
            $insights[] = "Avg task age at completion: " . round((float) $avgAge, 1) . " days";
        }

        // Aging tasks (open + older than 14 days)
        $aging = Task::open()->where('created_at', '<', Carbon::now()->subDays(14))->count();
        if ($aging > 0) {
            $insights[] = "Aging tasks: {$aging} open " . ($aging === 1 ? 'task' : 'tasks') . " older than 14 days";
        }

        return $insights;
    }

    /**
     * Get health/sustainability warnings.
     * SINGLE SOURCE OF TRUTH for burnout and overcommitment detection.
     *
     * @return array<string> Array of warning strings (empty = all good)
     */
    public function getHealthWarnings(): array
    {
        $warnings = [];
        $weekAgo = Carbon::now()->subDays(7);

        // Long days: 3+ days in last 7 with >8h of sessions
        $longDays = WorkSession::where('status', 'completed')
            ->where('started_at', '>=', $weekAgo)
            ->whereNotNull('ended_at')
            ->selectRaw("DATE(started_at) as day, SUM(EXTRACT(EPOCH FROM (ended_at - started_at)) / 3600) as hours")
            ->groupBy('day')
            ->havingRaw('SUM(EXTRACT(EPOCH FROM (ended_at - started_at)) / 3600) > 8')
            ->get();

        if ($longDays->count() >= 3) {
            $warnings[] = "Long days: {$longDays->count()} days over 8 hours in the last week";
        }

        // No rest days: sessions exist for every day in last 7
        $daysWorked = WorkSession::where('started_at', '>=', $weekAgo)
            ->selectRaw('COUNT(DISTINCT DATE(started_at)) as days')
            ->value('days');

        if ((int) $daysWorked >= 7) {
            $warnings[] = "No rest days: you worked all 7 days this week";
        }

        // Overcommitment: more than 20 open tasks
        $openCount = Task::open()->count();
        if ($openCount > 20) {
            $warnings[] = "Overcommitment: {$openCount} open tasks (consider pruning or deferring)";
        }

        // Streak at risk: yesterday had a plan but must_do not completed, and streak was 3+
        $yesterday = DailyPlan::whereDate('date', Carbon::yesterday())->first();
        if ($yesterday && !$yesterday->must_do_completed) {
            // Check if there was a streak before yesterday
            $streakBefore = 0;
            $date = Carbon::yesterday()->subDay();
            while (true) {
                $plan = DailyPlan::whereDate('date', $date)->first();
                if (!$plan || !$plan->must_do_completed) {
                    break;
                }
                $streakBefore++;
                $date = $date->subDay();
            }

            if ($streakBefore >= 3) {
                $warnings[] = "Streak at risk: {$streakBefore}-day streak broken yesterday";
            }
        }

        return $warnings;
    }
}
