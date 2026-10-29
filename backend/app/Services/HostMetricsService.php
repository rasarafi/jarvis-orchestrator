<?php

namespace App\Services;

use App\Enums\ResourcePressure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Reads Windows-host vitals published by the tray agent (tools/host-metrics-agent).
 *
 * Why a file: /proc only describes the inside of the WSL VM, so the existing
 * hang verdict is blind to everything Windows is doing — browsers, Docker
 * Desktop, and the WSL VM's own cost to the host. Shelling out to powershell.exe
 * costs ~1.2s per call; reading this file costs ~4ms.
 *
 * The agent is allowed to be absent. Every failure mode (no agent, no file,
 * truncated write, frozen clock) degrades to an explicit unavailable state so
 * the UI can say "agent down" instead of quietly rendering stale numbers.
 */
class HostMetricsService
{
    /** Cache key holding the learned WSL↔Windows clock offset. */
    private const OFFSET_KEY = 'host_metrics.clock_offset';

    /** Re-learn the offset periodically so a clock resync isn't locked out. */
    private const OFFSET_TTL = 3600 * 6;

    public function __construct(
        private ?string $path = null,
        private ?int $staleAfter = null,
    ) {
        $this->path ??= (string) config('services.host_metrics.path');
        $this->staleAfter ??= (int) config('services.host_metrics.stale_after', 30);
    }

    /**
     * Current host vitals, or an explicit unavailable payload.
     *
     * @return array{available:bool,status:string,reason:?string,...}
     */
    public function snapshot(): array
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            return $this->unavailable('offline', 'Host agent not running — no metrics file.');
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            return $this->unavailable('offline', 'Host metrics file is empty or unreadable.');
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['ts'], $data['mem'], $data['commit'])) {
            return $this->unavailable('offline', 'Host metrics file is malformed.');
        }

        $age = $this->ageSeconds((float) $data['ts']);
        if ($age > $this->staleAfter) {
            return $this->unavailable(
                'stale',
                'Host agent stopped reporting ' . $this->humanAge($age) . ' ago.',
                $age,
            );
        }

        return $this->present($data, $age);
    }

    /**
     * How long ago the agent wrote, immune to WSL↔Windows clock skew.
     *
     * WSL2's clock drifts from the host's (measured 34s apart on this machine
     * after a resume), and the file's mtime is written by Windows so it carries
     * the same offset — there is no WSL-domain timestamp to compare against.
     * A raw subtraction therefore either reports negative ages (letting a dead
     * agent look fresh) or permanently "stale" if the drift reverses.
     *
     * So we calibrate instead of trusting either clock — but only from readings
     * where the agent is provably alive, i.e. where `ts` has advanced since we
     * last looked. Without that guard, the first read after a cache clear would
     * absorb a dead agent's entire staleness into the "offset" and report it as
     * fresh forever. When we have no calibration yet we assume zero offset,
     * which fails toward "stale" and self-corrects on the next poll.
     */
    private function ageSeconds(float $ts): float
    {
        $delta = microtime(true) - $ts;

        $cal = Cache::get(self::OFFSET_KEY);
        $lastTs = is_array($cal) ? ($cal['ts'] ?? null) : null;
        $offset = is_array($cal) && is_numeric($cal['offset'] ?? null) ? (float) $cal['offset'] : null;

        // A changed `ts` proves the agent wrote between our two reads, so this
        // delta is genuine clock offset rather than staleness. The smallest such
        // delta is the offset: a live agent's delta oscillates over [skew, skew+interval].
        if ($lastTs !== null && (float) $lastTs !== $ts) {
            if ($offset === null || $delta < $offset) {
                $offset = $delta;
            } elseif ($delta - $offset > $this->staleAfter) {
                // The agent just proved it is alive, yet our baseline says it is
                // stale — so the baseline is what's wrong, not the agent. This is
                // what a clock correction looks like (fixing a 35s-fast host clock
                // moves the offset up, and a minimum-seeking baseline can never
                // follow it upward). Re-baseline rather than report a false outage.
                $offset = $delta;
            }
        }

        Cache::put(self::OFFSET_KEY, ['ts' => $ts, 'offset' => $offset], self::OFFSET_TTL);

        return max(0.0, $delta - ($offset ?? 0.0));
    }

    /** Normalized payload for a fresh reading. */
    private function present(array $data, float $age): array
    {
        $mem = $data['mem'];
        $commit = $data['commit'];

        $availableMb = (float) ($mem['available_mb'] ?? 0);
        $commitPct = (float) ($commit['pct'] ?? 0);
        $pressure = ResourcePressure::classifyHost($availableMb, $commitPct);

        return [
            'available' => true,
            'status' => 'ok',
            'reason' => null,
            'pressure' => $pressure->value,
            'hostname' => $data['host'] ?? null,
            'age_s' => round($age, 1),
            // Diagnostic: how far WSL's clock sits from the host's. Non-zero is
            // normal on WSL2; it only matters if someone debugs `sampled_at`.
            'clock_offset_s' => $this->learnedOffset(),
            'sampled_at' => Carbon::createFromTimestamp((float) $data['ts'])->toIso8601String(),
            'metrics' => [
                'cpu_pct' => (float) ($data['cpu_pct'] ?? 0),
                'cpu_cores' => (int) ($data['cpu_cores'] ?? 0),
                'mem_total_mb' => (int) ($mem['total_mb'] ?? 0),
                'mem_used_mb' => (int) ($mem['used_mb'] ?? 0),
                'mem_available_mb' => (int) $availableMb,
                'mem_pct' => (float) ($mem['pct'] ?? 0),
                'commit_used_mb' => (int) ($commit['used_mb'] ?? 0),
                'commit_limit_mb' => (int) ($commit['limit_mb'] ?? 0),
                'commit_pct' => $commitPct,
                // What the WSL VM itself costs the host — the bridge between
                // this panel's numbers and the /proc numbers above it.
                'wsl_mb' => (int) ($data['wsl_mb'] ?? 0),
            ],
            'top' => $this->topProcesses($data['top'] ?? []),
        ];
    }

    /** @return list<array{name:string,mb:int}> */
    private function topProcesses(mixed $top): array
    {
        if (! is_array($top)) {
            return [];
        }

        return collect($top)
            ->filter(fn ($row) => is_array($row) && isset($row['name'], $row['mb']))
            ->map(fn (array $row) => ['name' => (string) $row['name'], 'mb' => (int) $row['mb']])
            ->values()
            ->all();
    }

    /** The calibrated clock offset, or null before the first two readings. */
    private function learnedOffset(): ?float
    {
        $cal = Cache::get(self::OFFSET_KEY);
        $offset = is_array($cal) ? ($cal['offset'] ?? null) : null;

        return is_numeric($offset) ? round((float) $offset, 1) : null;
    }

    private function unavailable(string $status, string $reason, ?float $age = null): array
    {
        return [
            'available' => false,
            'status' => $status,
            'reason' => $reason,
            'pressure' => null,
            'hostname' => null,
            'age_s' => $age !== null ? round($age, 1) : null,
            'clock_offset_s' => null,
            'sampled_at' => null,
            'metrics' => null,
            'top' => [],
        ];
    }

    private function humanAge(float $seconds): string
    {
        if ($seconds < 120) {
            return round($seconds) . 's';
        }
        if ($seconds < 7200) {
            return round($seconds / 60) . 'm';
        }

        return round($seconds / 3600) . 'h';
    }
}
