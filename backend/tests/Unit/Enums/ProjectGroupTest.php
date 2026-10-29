<?php

namespace Tests\Unit\Enums;

use App\Enums\ProjectGroup;
use PHPUnit\Framework\TestCase;

class ProjectGroupTest extends TestCase
{
    public function test_has_four_groups(): void
    {
        $this->assertSame('mfb', ProjectGroup::MFB->value);
        $this->assertSame('fa', ProjectGroup::FA->value);
        $this->assertSame('freelance', ProjectGroup::FREELANCE->value);
        $this->assertSame('personal', ProjectGroup::PERSONAL->value);
    }

    public function test_labels_are_human_readable(): void
    {
        $this->assertSame('MFB', ProjectGroup::MFB->label());
        $this->assertSame('FA', ProjectGroup::FA->label());
        $this->assertSame('Freelance', ProjectGroup::FREELANCE->label());
        $this->assertSame('Self', ProjectGroup::PERSONAL->label());
    }
}
