<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\KiraSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KiraSyncPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_assistant_data_path_is_used(): void
    {
        $dir = sys_get_temp_dir() . '/kira-path-test-' . uniqid();
        mkdir($dir . '/common/assistant-data', 0777, true);
        file_put_contents(
            $dir . '/common/assistant-data/tasks.md',
            "# Tasks\n\n<!-- sync-manifest\n[{\"id\": null, \"title\": \"from custom path\", \"priority\": \"high\", \"status\": \"todo\"}]\n-->\n"
        );

        $project = Project::create([
            'slug' => 'custom-path-test', 'name' => 'CP', 'purpose' => 't',
            'stack' => ['php'], 'local_path' => $dir, 'status' => 'active',
            'assistant_data_path' => 'common/assistant-data',
        ]);

        $result = app(KiraSyncService::class)->importFromProject($project);

        $this->assertSame(1, $result['created']);

        @unlink($dir . '/common/assistant-data/tasks.md');
        @rmdir($dir . '/common/assistant-data');
        @rmdir($dir . '/common');
        @rmdir($dir);
    }
}
