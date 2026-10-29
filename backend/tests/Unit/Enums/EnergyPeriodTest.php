<?php

namespace Tests\Unit\Enums;

use App\Enums\EnergyPeriod;
use PHPUnit\Framework\TestCase;

class EnergyPeriodTest extends TestCase
{
    public function test_morning_hours(): void
    {
        $this->assertSame(EnergyPeriod::MORNING, EnergyPeriod::fromHour(6));
        $this->assertSame(EnergyPeriod::MORNING, EnergyPeriod::fromHour(9));
    }

    public function test_midday_hours(): void
    {
        $this->assertSame(EnergyPeriod::MIDDAY, EnergyPeriod::fromHour(10));
        $this->assertSame(EnergyPeriod::MIDDAY, EnergyPeriod::fromHour(13));
    }

    public function test_afternoon_hours(): void
    {
        $this->assertSame(EnergyPeriod::AFTERNOON, EnergyPeriod::fromHour(14));
        $this->assertSame(EnergyPeriod::AFTERNOON, EnergyPeriod::fromHour(17));
    }

    public function test_evening_hours(): void
    {
        $this->assertSame(EnergyPeriod::EVENING, EnergyPeriod::fromHour(18));
        $this->assertSame(EnergyPeriod::EVENING, EnergyPeriod::fromHour(23));
        $this->assertSame(EnergyPeriod::EVENING, EnergyPeriod::fromHour(0));
        $this->assertSame(EnergyPeriod::EVENING, EnergyPeriod::fromHour(5));
    }

    public function test_boundary_hours(): void
    {
        $this->assertSame(EnergyPeriod::EVENING, EnergyPeriod::fromHour(5));
        $this->assertSame(EnergyPeriod::MORNING, EnergyPeriod::fromHour(6));
        $this->assertSame(EnergyPeriod::MIDDAY, EnergyPeriod::fromHour(10));
        $this->assertSame(EnergyPeriod::AFTERNOON, EnergyPeriod::fromHour(14));
        $this->assertSame(EnergyPeriod::EVENING, EnergyPeriod::fromHour(18));
    }

    public function test_labels_are_not_empty(): void
    {
        foreach (EnergyPeriod::cases() as $period) {
            $this->assertNotEmpty($period->label());
        }
    }
}
