<?php

namespace Tests\Unit\Enums;

use App\Enums\FocusSessionStatus;
use PHPUnit\Framework\TestCase;

class FocusSessionStatusTest extends TestCase
{
    public function test_has_three_statuses(): void
    {
        $this->assertSame('active', FocusSessionStatus::ACTIVE->value);
        $this->assertSame('paused', FocusSessionStatus::PAUSED->value);
        $this->assertSame('ended', FocusSessionStatus::ENDED->value);
    }

    public function test_open_statuses_excludes_ended(): void
    {
        $this->assertEqualsCanonicalizing(
            ['active', 'paused'],
            FocusSessionStatus::openValues(),
        );
    }
}
