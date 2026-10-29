<?php

namespace App\Enums;

/**
 * System-load verdict for the machine running Jarvis + Claude sessions.
 * Thresholds are WSL2-tuned: swap usage and load-per-core predict a hang far
 * better than RAM% alone (WSL feels fine at 90% RAM until it swaps hard).
 */
enum ResourcePressure: string
{
    case OK = 'ok';
    case WARNING = 'warning';
    case CRITICAL = 'critical';

    // Fraction of swap in use.
    private const SWAP_WARN = 0.15;
    private const SWAP_CRIT = 0.40;

    // 1-minute load average divided by core count.
    private const LOAD_WARN = 1.5;
    private const LOAD_CRIT = 2.5;

    // Absolute available memory floor (MB) before a hang is imminent.
    private const AVAIL_CRIT_MB = 1024.0;

    // Windows-host floors. Higher than the WSL ones because the host has to keep
    // the desktop, the WSL VM and Docker alive — 2 GB free on the host is far
    // tighter than 2 GB free inside the VM.
    private const HOST_AVAIL_WARN_MB = 4096.0;
    private const HOST_AVAIL_CRIT_MB = 2048.0;

    // Commit charge as a fraction of the commit limit (RAM + pagefile). Near the
    // limit Windows starts refusing allocations outright, which is what actually
    // freezes the machine — it leads RAM% as a predictor.
    private const HOST_COMMIT_WARN_PCT = 85.0;
    private const HOST_COMMIT_CRIT_PCT = 95.0;

    /**
     * Pure classification from the three signals that actually predict a WSL hang.
     * Kept side-effect-free so it is unit-testable without touching /proc.
     */
    public static function classify(float $loadPerCore, float $swapPct, float $availMb): self
    {
        if ($swapPct > self::SWAP_CRIT || $availMb < self::AVAIL_CRIT_MB || $loadPerCore > self::LOAD_CRIT) {
            return self::CRITICAL;
        }

        if ($swapPct > self::SWAP_WARN || $loadPerCore > self::LOAD_WARN) {
            return self::WARNING;
        }

        return self::OK;
    }

    /**
     * Same three verdicts for the Windows host, from the two signals that predict
     * a host-level freeze: absolute free RAM and commit charge against its limit.
     * Side-effect-free for the same reason as classify().
     */
    public static function classifyHost(float $availableMb, float $commitPct): self
    {
        if ($availableMb < self::HOST_AVAIL_CRIT_MB || $commitPct >= self::HOST_COMMIT_CRIT_PCT) {
            return self::CRITICAL;
        }

        if ($availableMb < self::HOST_AVAIL_WARN_MB || $commitPct >= self::HOST_COMMIT_WARN_PCT) {
            return self::WARNING;
        }

        return self::OK;
    }

    /** Rank for comparing two verdicts (higher = worse). */
    public function severity(): int
    {
        return match ($this) {
            self::OK => 0,
            self::WARNING => 1,
            self::CRITICAL => 2,
        };
    }

    /** The worse of two verdicts — used to fold host pressure into the WSL verdict. */
    public function worseOf(self $other): self
    {
        return $other->severity() > $this->severity() ? $other : $this;
    }
}
