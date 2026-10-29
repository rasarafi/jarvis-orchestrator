<?php

namespace Tests\Unit\Enums;

use App\Enums\TimeBlockKind;
use PHPUnit\Framework\TestCase;

class TimeBlockKindTest extends TestCase
{
    public function test_has_work_and_personal_cases(): void
    {
        $this->assertSame('work', TimeBlockKind::WORK->value);
        $this->assertSame('personal', TimeBlockKind::PERSONAL->value);
    }
}
