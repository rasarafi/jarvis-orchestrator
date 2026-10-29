<?php

namespace App\Services;

use App\Enums\ResourcePressure;
use App\Models\Project;
use App\Models\ResourceSample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads the host machine's live vitals from /proc, attributes memory to the
 * Claude sessions eating it (by matching each process cwd to a Jarvis project),
 * and computes a hang-risk verdict. Live snapshots feed the 3s poll; sample()
 * persists a row every ~60s so the trend survives reloads.
 *
 * WSL2 note: /proc/meminfo reports the VM ceiling (your .wslconfig cap), which
 * is exactly the number that matters. Per-process RSS double-counts shared
 * pages, so we rank sessions rather than claiming an exact sum.
 */
class SystemResourceService
{
    /** Max session rows returned in a snapshot. */
    private const MAX_SESSIONS = 8;

    public function __construct(
        private HostMetricsService $hostMetrics,
    ) {}

    /** Live snapshot: vitals + verdict + per-session memory attribution. */
    public function snapshot(): array
    {
        $mem = $this->readMemory();
        $load = $this->readLoad();
        $cores = max(1, $this->readCores());
        $sessions = $this->readSessions($mem['used_mb']);

        $loadPerCore = round($load / $cores, 2);
        $swapPct = $mem['swap_total_mb'] > 0 ? $mem['swap_used_mb'] / $mem['swap_total_mb'] : 0.0;

        $pressure = ResourcePressure::classify($loadPerCore, $swapPct, $mem['available_mb']);

        $metrics = [
            'cpu_load' => round($load, 2),
            'cores' => $cores,
            'load_per_core' => $loadPerCore,
            'mem_used_mb' => $mem['used_mb'],
            'mem_total_mb' => $mem['total_mb'],
            'mem_available_mb' => $mem['available_mb'],
            'mem_pct' => $mem['total_mb'] > 0 ? (int) round($mem['used_mb'] / $mem['total_mb'] * 100) : 0,
            'swap_used_mb' => $mem['swap_used_mb'],
            'swap_total_mb' => $mem['swap_total_mb'],
            'swap_pct' => (int) round($swapPct * 100),
            'session_count' => count($sessions),
        ];

        // The WSL view can't see the host. When the host is the worse signal it
        // owns the banner — that blindness is what this whole panel exists to fix.
        $host = $this->hostMetrics->snapshot();
        $hostPressure = $host['pressure'] !== null ? ResourcePressure::from($host['pressure']) : null;
        $overall = $hostPressure !== null ? $pressure->worseOf($hostPressure) : $pressure;

        $verdict = $hostPressure !== null && $hostPressure->severity() > $pressure->severity()
            ? $this->hostVerdictCopy($hostPressure, $host['metrics'])
            : $this->verdictCopy($pressure, $metrics);

        return [
            'pressure' => $overall->value,
            ...$verdict,
            'metrics' => $metrics,
            'sessions' => $sessions,
            'host' => $host,
            'sampled_at' => now()->toIso8601String(),
        ];
    }

    /** Persist one sample row from the current snapshot (called by the scheduler). */
    public function sample(): ResourceSample
    {
        $s = $this->snapshot();
        $m = $s['metrics'];
        $host = $s['host'];
        $hm = $host['metrics'];

        return ResourceSample::create([
            'cpu_load' => $m['cpu_load'],
            'cores' => $m['cores'],
            'mem_used_mb' => $m['mem_used_mb'],
            'mem_total_mb' => $m['mem_total_mb'],
            'swap_used_mb' => $m['swap_used_mb'],
            'swap_total_mb' => $m['swap_total_mb'],
            'session_count' => $m['session_count'],
            'pressure' => $s['pressure'],
            // Null whenever the tray agent is down — the trend simply has a gap there.
            'host_mem_pct' => $hm['mem_pct'] ?? null,
            'host_commit_pct' => $hm['commit_pct'] ?? null,
            'host_pressure' => $host['pressure'],
            'sampled_at' => now(),
        ]);
    }

    /** Persisted trend for the sparkline, oldest → newest within the window. */
    public function trend(int $windowMinutes): array
    {
        return ResourceSample::query()
            ->where('sampled_at', '>=', now()->subMinutes($windowMinutes))
            ->orderBy('sampled_at')
            ->get()
            ->map(fn (ResourceSample $r) => [
                'sampled_at' => $r->sampled_at->toIso8601String(),
                'cpu_load' => (float) $r->cpu_load,
                'cores' => $r->cores,
                'mem_pct' => $r->mem_total_mb > 0 ? (int) round($r->mem_used_mb / $r->mem_total_mb * 100) : 0,
                'swap_pct' => $r->swap_total_mb > 0 ? (int) round($r->swap_used_mb / $r->swap_total_mb * 100) : 0,
                'pressure' => $r->pressure->value,
                // Null for samples taken while the host agent was down.
                'host_mem_pct' => $r->host_mem_pct,
                'host_commit_pct' => $r->host_commit_pct,
            ])
            ->all();
    }

    /** Retention: drop samples older than N days. Returns rows deleted. */
    public function prune(int $days = 7): int
    {
        return ResourceSample::query()
            ->where('sampled_at', '<', now()->subDays($days))
            ->delete();
    }

    // ── /proc readers ──────────────────────────────────────────────────────

    /** MemTotal/MemAvailable + swap from /proc/meminfo, all in MB. */
    private function readMemory(): array
    {
        $raw = @file_get_contents('/proc/meminfo') ?: '';
        $kb = function (string $key) use ($raw): int {
            return preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\s*kB/m', $raw, $m)
                ? (int) $m[1] : 0;
        };

        $totalMb = intdiv($kb('MemTotal'), 1024);
        $availMb = intdiv($kb('MemAvailable'), 1024);
        $swapTotalMb = intdiv($kb('SwapTotal'), 1024);
        $swapFreeMb = intdiv($kb('SwapFree'), 1024);

        return [
            'total_mb' => $totalMb,
            'available_mb' => (float) $availMb,
            'used_mb' => max(0, $totalMb - $availMb),
            'swap_total_mb' => $swapTotalMb,
            'swap_used_mb' => max(0, $swapTotalMb - $swapFreeMb),
        ];
    }

    /** 1-minute load average from /proc/loadavg. */
    private function readLoad(): float
    {
        $raw = @file_get_contents('/proc/loadavg') ?: '0';

        return (float) explode(' ', trim($raw))[0];
    }

    /** Logical core count from /proc/cpuinfo. */
    private function readCores(): int
    {
        $raw = @file_get_contents('/proc/cpuinfo') ?: '';

        return max(1, preg_match_all('/^processor\s*:/m', $raw));
    }

    /**
     * Walk /proc for live `claude` processes, sum their RSS by owning project
     * (longest-prefix cwd match against projects.local_path), rank by memory.
     */
    private function readSessions(int $usedMb): array
    {
        $projects = $this->projectsByPathLength();
        $byProject = []; // slug|path => ['name','slug','path','rss_mb','pids']

        foreach (glob('/proc/[0-9]*') ?: [] as $procDir) {
            $cmdline = @file_get_contents("$procDir/cmdline");
            if ($cmdline === false || stripos($cmdline, 'claude') === false) {
                continue;
            }

            $rssMb = $this->readRssMb("$procDir/status");
            if ($rssMb <= 0) {
                continue;
            }

            $cwd = @readlink("$procDir/cwd") ?: '';
            $match = $this->matchProject($cwd, $projects);
            $key = $match['slug'] ?? ($cwd !== '' ? $cwd : $procDir);

            if (! isset($byProject[$key])) {
                $byProject[$key] = [
                    'project' => $match['name'] ?? ($cwd !== '' ? basename($cwd) : 'unknown'),
                    'slug' => $match['slug'] ?? null,
                    'path' => $cwd !== '' ? $this->tildePath($cwd) : '—',
                    'rss_mb' => 0,
                    'pids' => 0,
                ];
            }
            $byProject[$key]['rss_mb'] += $rssMb;
            $byProject[$key]['pids']++;
        }

        return collect($byProject)
            ->sortByDesc('rss_mb')
            ->take(self::MAX_SESSIONS)
            ->map(fn (array $row) => [
                ...$row,
                'pct_of_used' => $usedMb > 0 ? (int) round($row['rss_mb'] / $usedMb * 100) : 0,
            ])
            ->values()
            ->all();
    }

    /** VmRSS (kB) → MB from a /proc/<pid>/status file. */
    private function readRssMb(string $statusPath): int
    {
        $raw = @file_get_contents($statusPath);
        if ($raw === false || ! preg_match('/^VmRSS:\s+(\d+)\s*kB/m', $raw, $m)) {
            return 0;
        }

        return intdiv((int) $m[1], 1024);
    }

    /** @return Collection<int, Project> projects with a local_path, longest first. */
    private function projectsByPathLength(): Collection
    {
        return Project::query()
            ->whereNotNull('local_path')
            ->where('local_path', '!=', '')
            ->get(['name', 'slug', 'local_path'])
            ->sortByDesc(fn (Project $p) => strlen($p->local_path))
            ->values();
    }

    /** @return array{name:string,slug:string}|array{} */
    private function matchProject(string $cwd, Collection $projects): array
    {
        if ($cwd === '') {
            return [];
        }
        foreach ($projects as $project) {
            $base = rtrim($project->local_path, '/');
            if ($cwd === $project->local_path || str_starts_with($cwd, $base . '/')) {
                return ['name' => $project->name, 'slug' => $project->slug];
            }
        }

        return [];
    }

    private function tildePath(string $path): string
    {
        $home = getenv('HOME') ?: '';

        return $home !== '' && str_starts_with($path, $home)
            ? '~' . substr($path, strlen($home))
            : $path;
    }

    // ── verdict copy ───────────────────────────────────────────────────────

    /** Human message + detail for the banner, keyed off the dominant signal. */
    private function verdictCopy(ResourcePressure $pressure, array $m): array
    {
        $sessions = $m['session_count'];
        $load = "load {$m['cpu_load']} across {$m['cores']} cores";
        $swap = $m['swap_used_mb'] >= 1024
            ? round($m['swap_used_mb'] / 1024, 1) . ' GB swapped'
            : "{$m['swap_used_mb']} MB swapped";
        $free = $m['mem_available_mb'] >= 1024
            ? round($m['mem_available_mb'] / 1024, 1) . ' GB free'
            : round($m['mem_available_mb']) . ' MB free';

        return match ($pressure) {
            ResourcePressure::CRITICAL => [
                'message' => 'Hang imminent — the machine is thrashing swap.',
                'detail' => "{$load} · {$swap} · {$free}. WSL will freeze soon — drop a session.",
            ],
            ResourcePressure::WARNING => [
                'message' => "Getting tight — pressure is building under {$sessions} active sessions.",
                'detail' => "{$load} · {$swap} and rising. Consider pausing a session.",
            ],
            ResourcePressure::OK => [
                'message' => 'All clear — plenty of headroom.',
                'detail' => "{$load} · {$free} · {$sessions} active sessions.",
            ],
        };
    }

    /**
     * Banner copy for when Windows — not WSL — is the binding constraint. Names
     * the host explicitly so the numbers aren't mistaken for the /proc ones.
     */
    private function hostVerdictCopy(ResourcePressure $pressure, array $hm): array
    {
        $free = $hm['mem_available_mb'] >= 1024
            ? round($hm['mem_available_mb'] / 1024, 1) . ' GB free'
            : "{$hm['mem_available_mb']} MB free";
        $commit = "commit {$hm['commit_pct']}%";
        $wsl = $hm['wsl_mb'] >= 1024
            ? 'WSL holds ' . round($hm['wsl_mb'] / 1024, 1) . ' GB'
            : "WSL holds {$hm['wsl_mb']} MB";

        return match ($pressure) {
            ResourcePressure::CRITICAL => [
                'message' => 'Windows is out of headroom — WSL looks fine but the host does not.',
                'detail' => "Host: {$free} · {$commit} · {$wsl}. Close Windows apps or drop a session.",
            ],
            ResourcePressure::WARNING => [
                'message' => 'Windows host is tightening — pressure is outside WSL.',
                'detail' => "Host: {$free} · {$commit} · {$wsl}.",
            ],
            ResourcePressure::OK => [
                'message' => 'All clear — plenty of headroom.',
                'detail' => "Host: {$free} · {$commit} · {$wsl}.",
            ],
        };
    }
}
