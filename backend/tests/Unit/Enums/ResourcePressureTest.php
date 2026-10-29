<?php

namespace Tests\Unit\Enums;

use App\Enums\ResourcePressure;
use PHPUnit\Framework\TestCase;

class ResourcePressureTest extends TestCase
{
    public function test_calm_machine_is_ok(): void
    {
        // low load, no swap, ample free RAM
        $this->assertSame(ResourcePressure::OK, ResourcePressure::classify(0.4, 0.0, 8000));
    }

    public function test_rising_load_trips_warning(): void
    {
        $this->assertSame(ResourcePressure::WARNING, ResourcePressure::classify(1.6, 0.0, 8000));
    }

    public function test_light_swap_trips_warning(): void
    {
        $this->assertSame(ResourcePressure::WARNING, ResourcePressure::classify(0.5, 0.20, 8000));
    }

    public function test_heavy_swap_is_critical(): void
    {
        $this->assertSame(ResourcePressure::CRITICAL, ResourcePressure::classify(0.5, 0.45, 8000));
    }

    public function test_starved_ram_is_critical_regardless_of_load(): void
    {
        $this->assertSame(ResourcePressure::CRITICAL, ResourcePressure::classify(0.2, 0.0, 512));
    }

    public function test_high_load_per_core_is_critical(): void
    {
        $this->assertSame(ResourcePressure::CRITICAL, ResourcePressure::classify(2.6, 0.0, 8000));
    }

    public function test_boundaries_do_not_over_escalate(): void
    {
        // exactly at the warn thresholds → still ok (strict >)
        $this->assertSame(ResourcePressure::OK, ResourcePressure::classify(1.5, 0.15, 8000));
        // exactly at the crit thresholds → still warning
        $this->assertSame(ResourcePressure::WARNING, ResourcePressure::classify(2.5, 0.40, 8000));
    }

    // ── Windows host ───────────────────────────────────────────────────────

    public function test_roomy_host_is_ok(): void
    {
        $this->assertSame(ResourcePressure::OK, ResourcePressure::classifyHost(12000, 40.0));
    }

    public function test_host_below_free_ram_floor_warns(): void
    {
        $this->assertSame(ResourcePressure::WARNING, ResourcePressure::classifyHost(3000, 40.0));
    }

    public function test_host_starved_of_ram_is_critical(): void
    {
        $this->assertSame(ResourcePressure::CRITICAL, ResourcePressure::classifyHost(1500, 40.0));
    }

    /** Commit exhaustion freezes Windows even while free RAM still looks fine. */
    public function test_high_commit_is_critical_despite_ample_free_ram(): void
    {
        $this->assertSame(ResourcePressure::CRITICAL, ResourcePressure::classifyHost(12000, 96.0));
    }

    public function test_rising_commit_warns(): void
    {
        $this->assertSame(ResourcePressure::WARNING, ResourcePressure::classifyHost(12000, 88.0));
    }

    public function test_worse_of_picks_the_dominant_signal(): void
    {
        $this->assertSame(
            ResourcePressure::CRITICAL,
            ResourcePressure::OK->worseOf(ResourcePressure::CRITICAL),
        );
        // A healthy host must never soften a genuine WSL warning.
        $this->assertSame(
            ResourcePressure::WARNING,
            ResourcePressure::WARNING->worseOf(ResourcePressure::OK),
        );
    }
}
