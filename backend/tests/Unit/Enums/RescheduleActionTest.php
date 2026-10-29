<?php

namespace Tests\Unit\Enums;

use App\Enums\RescheduleAction;
use PHPUnit\Framework\TestCase;

class RescheduleActionTest extends TestCase
{
    public function test_has_six_actions(): void
    {
        $this->assertSame('push_1hr', RescheduleAction::PUSH_1HR->value);
        $this->assertSame('push_evening', RescheduleAction::PUSH_EVENING->value);
        $this->assertSame('push_tomorrow', RescheduleAction::PUSH_TOMORROW->value);
        $this->assertSame('skip', RescheduleAction::SKIP->value);
        $this->assertSame('to_backlog', RescheduleAction::TO_BACKLOG->value);
        $this->assertSame('to_time', RescheduleAction::TO_TIME->value);
    }
}
