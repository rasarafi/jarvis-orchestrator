<?php

namespace Tests\Unit\Services;

use App\Services\SessionScannerService;
use Tests\TestCase;

class SessionScannerServiceTest extends TestCase
{
    private SessionScannerService $service;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SessionScannerService::class);
        $this->tempDir = sys_get_temp_dir() . '/scanner-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_parse_session_file_extracts_metadata(): void
    {
        $sessionId = 'test-session-' . uniqid();
        $filePath = $this->createFixtureFile($sessionId);

        $result = $this->invokePrivateMethod($this->service, 'parseSessionFile', [$filePath]);

        $this->assertEquals($sessionId, $result['metadata']['session_id']);
        $this->assertEquals('Hello, can you help?', $result['metadata']['first_prompt']);
        $this->assertNotNull($result['metadata']['first_prompt_timestamp']);
        $this->assertNotNull($result['metadata']['last_activity_timestamp']);
        $this->assertEquals('main', $result['metadata']['git_branch']);
        $this->assertEquals('/home/dev/projects/test', $result['metadata']['cwd']);
        $this->assertEquals('1.0.0', $result['metadata']['claude_version']);
        $this->assertEquals(3, $result['metadata']['message_count']); // 1 user + 2 assistant
        $this->assertEquals(1, $result['metadata']['turn_count']); // Only 1 real user turn
        $this->assertGreaterThan(0, $result['metadata']['total_input_tokens']);
        $this->assertGreaterThan(0, $result['metadata']['total_output_tokens']);
        $this->assertGreaterThan(0, $result['metadata']['estimated_cost_usd']);
    }

    public function test_parse_session_file_extracts_messages(): void
    {
        $sessionId = 'test-session-' . uniqid();
        $filePath = $this->createFixtureFile($sessionId);

        $result = $this->invokePrivateMethod($this->service, 'parseSessionFile', [$filePath]);

        $this->assertCount(3, $result['messages']);

        // First message: user
        $this->assertEquals('user', $result['messages'][0]['message_type']);
        $this->assertStringContainsString('Hello', $result['messages'][0]['content_text']);
        $this->assertEquals(1, $result['messages'][0]['sequence_number']);

        // Second message: assistant with text
        $this->assertEquals('assistant', $result['messages'][1]['message_type']);
        $this->assertStringContainsString('Sure, I can help', $result['messages'][1]['content_text']);
        $this->assertEquals('claude-opus-4-6', $result['messages'][1]['model']);
        $this->assertEquals(1500, $result['messages'][1]['input_tokens']);
    }

    public function test_parse_session_file_extracts_tool_names(): void
    {
        $sessionId = 'test-session-' . uniqid();
        $filePath = $this->createFixtureFileWithTools($sessionId);

        $result = $this->invokePrivateMethod($this->service, 'parseSessionFile', [$filePath]);

        // Find the assistant message with tools
        $assistantMsg = null;
        foreach ($result['messages'] as $msg) {
            if ($msg['message_type'] === 'assistant' && $msg['tool_names'] !== null) {
                $assistantMsg = $msg;
                break;
            }
        }

        $this->assertNotNull($assistantMsg);
        $this->assertContains('Read', $assistantMsg['tool_names']);
        $this->assertContains('Bash', $assistantMsg['tool_names']);
    }

    public function test_turn_count_ignores_tool_result_messages(): void
    {
        $sessionId = 'test-session-' . uniqid();
        $filePath = $this->createFixtureFileWithToolResults($sessionId);

        $result = $this->invokePrivateMethod($this->service, 'parseSessionFile', [$filePath]);

        // Should count only real user turns, not tool-result user messages
        $this->assertEquals(1, $result['metadata']['turn_count']);
    }

    public function test_fork_detection(): void
    {
        $sessionId = 'test-session-' . uniqid();
        $filePath = $this->createFixtureFileWithFork($sessionId);

        $result = $this->invokePrivateMethod($this->service, 'parseSessionFile', [$filePath]);

        $this->assertEquals(1, $result['metadata']['fork_count']);
    }

    public function test_cost_calculation(): void
    {
        $cost = $this->invokePrivateMethod(
            $this->service,
            'calculateCost',
            ['claude-opus-4-6', 1_000_000, 1_000_000, 0, 0]
        );

        // input: 1M * $5/1M = $5, output: 1M * $25/1M = $25 (current opus-4-6 pricing)
        $this->assertEquals(30.0, $cost);
    }

    public function test_cost_calculation_unknown_model(): void
    {
        $cost = $this->invokePrivateMethod(
            $this->service,
            'calculateCost',
            ['unknown-model-v9', 1000, 500, 0, 0]
        );

        $this->assertEquals(0.0, $cost);
    }

    public function test_cost_calculation_with_cache_tokens(): void
    {
        $cost = $this->invokePrivateMethod(
            $this->service,
            'calculateCost',
            ['claude-haiku-4-5-20251001', 0, 0, 1_000_000, 1_000_000]
        );

        // cache_create: 1M * $1.25/1M = $1.25, cache_read: 1M * $0.10/1M = $0.10
        $this->assertEquals(1.35, $cost);
    }

    public function test_cost_calculation_prices_opus_5(): void
    {
        // Regression: claude-opus-5 had no rate entry, so ~30k messages of real
        // usage were silently costed at $0.
        $cost = $this->invokePrivateMethod(
            $this->service,
            'calculateCost',
            ['claude-opus-5', 1_000_000, 1_000_000, 0, 0]
        );

        $this->assertEquals(30.0, $cost);
    }

    public function test_cost_calculation_prices_1h_cache_writes_higher(): void
    {
        // 1M cache writes on haiku ($1/1M input): all 5m = 1.25x = $1.25,
        // all 1h = 2x = $2.00.
        $fiveMinute = $this->invokePrivateMethod(
            $this->service,
            'calculateCost',
            ['claude-haiku-4-5-20251001', 0, 0, 1_000_000, 0, 0]
        );
        $oneHour = $this->invokePrivateMethod(
            $this->service,
            'calculateCost',
            ['claude-haiku-4-5-20251001', 0, 0, 1_000_000, 0, 1_000_000]
        );

        $this->assertEquals(1.25, $fiveMinute);
        $this->assertEquals(2.0, $oneHour);
    }

    public function test_cost_calculation_splits_mixed_cache_tiers(): void
    {
        // 1M total writes, 400k of them at 1h TTL:
        // 600k * 1.25 + 400k * 2.0 = $0.75 + $0.80 = $1.55
        $cost = $this->invokePrivateMethod(
            $this->service,
            'calculateCost',
            ['claude-haiku-4-5-20251001', 0, 0, 1_000_000, 0, 400_000]
        );

        $this->assertEqualsWithDelta(1.55, $cost, 0.0001);
    }

    public function test_cost_calculation_clamps_1h_portion_to_total(): void
    {
        // A 1h figure larger than the total it belongs to must not inflate cost
        // beyond pricing the whole write at the 1h rate.
        $cost = $this->invokePrivateMethod(
            $this->service,
            'calculateCost',
            ['claude-haiku-4-5-20251001', 0, 0, 1_000_000, 0, 5_000_000]
        );

        $this->assertEquals(2.0, $cost);
    }

    public function test_unknown_model_is_recorded_for_warning(): void
    {
        $this->invokePrivateMethod(
            $this->service,
            'calculateCost',
            ['unknown-model-v9', 1000, 500, 0, 0]
        );

        $this->assertSame(['unknown-model-v9' => 1], $this->service->unpricedModels());
    }

    public function test_parse_session_file_captures_1h_cache_split(): void
    {
        $sessionId = 'ttl-session-' . uniqid();
        $filePath = $this->tempDir . '/' . $sessionId . '.jsonl';
        file_put_contents($filePath, json_encode([
            'type' => 'assistant',
            'uuid' => 'a1',
            'parentUuid' => null,
            'sessionId' => $sessionId,
            'timestamp' => '2026-08-19T04:00:00.000Z',
            'cwd' => '/home/dev/projects/test',
            'message' => [
                'model' => 'claude-opus-5',
                'content' => [['type' => 'text', 'text' => 'hi']],
                'usage' => [
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'cache_creation_input_tokens' => 1000,
                    'cache_read_input_tokens' => 0,
                    'cache_creation' => [
                        'ephemeral_1h_input_tokens' => 1000,
                        'ephemeral_5m_input_tokens' => 0,
                    ],
                ],
            ],
        ]) . "\n");

        $result = $this->invokePrivateMethod($this->service, 'parseSessionFile', [$filePath]);

        $this->assertEquals(1000, $result['metadata']['total_cache_creation_tokens']);
        $this->assertEquals(1000, $result['metadata']['total_cache_creation_1h_tokens']);
        $this->assertEquals(1000, $result['messages'][0]['cache_creation_1h_tokens']);
        // 1000 writes at 1h on opus-5 ($5/1M input): 1000/1e6 * 5 * 2 = $0.01
        $this->assertEqualsWithDelta(0.01, $result['metadata']['estimated_cost_usd'], 0.000001);
    }

    public function test_extract_content_text_from_string(): void
    {
        $text = $this->invokePrivateMethod($this->service, 'extractContentText', ['Hello world']);
        $this->assertEquals('Hello world', $text);
    }

    public function test_extract_content_text_strips_xml(): void
    {
        $text = $this->invokePrivateMethod(
            $this->service,
            'extractContentText',
            ['<tag>content</tag> and <b>bold</b>']
        );
        $this->assertEquals('content and bold', $text);
    }

    public function test_extract_content_text_from_blocks(): void
    {
        $blocks = [
            ['type' => 'text', 'text' => 'Hello there.'],
            ['type' => 'tool_use', 'name' => 'Read', 'input' => []],
            ['type' => 'thinking', 'thinking' => 'Let me think...'],
        ];

        $text = $this->invokePrivateMethod($this->service, 'extractContentText', [$blocks]);
        $this->assertStringContainsString('Hello there.', $text);
        $this->assertStringContainsString('Let me think...', $text);
        $this->assertStringNotContainsString('Read', $text);
    }

    public function test_truncate_word_boundary(): void
    {
        $longText = 'This is a relatively long sentence that definitely should be truncated at a word boundary correctly.';

        $result = $this->invokePrivateMethod($this->service, 'truncateWordBoundary', [$longText, 30]);

        $this->assertLessThanOrEqual(30, mb_strlen($result));
        // Should not end in the middle of a word (no partial "definitely")
        $this->assertStringNotContainsString('defin', $result);
    }

    public function test_truncate_word_boundary_short_string_unchanged(): void
    {
        $text = 'Short text';
        $result = $this->invokePrivateMethod($this->service, 'truncateWordBoundary', [$text, 100]);
        $this->assertEquals($text, $result);
    }

    public function test_ensure_project_creates_new_project(): void
    {
        $dirName = '-home-dev-projects-test-scanner-' . uniqid();

        $id = $this->invokePrivateMethod($this->service, 'ensureProject', [$dirName]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $project = \Illuminate\Support\Facades\DB::table('sessions.projects')
            ->where('id', $id)
            ->first();

        $this->assertNotNull($project);
        $this->assertEquals($dirName, $project->encoded_path);
        $this->assertStringStartsWith('/', $project->decoded_path);

        // Clean up
        \Illuminate\Support\Facades\DB::table('sessions.projects')->where('id', $id)->delete();
    }

    public function test_ensure_project_returns_existing_id(): void
    {
        $dirName = '-home-dev-projects-test-existing-' . uniqid();

        $id1 = $this->invokePrivateMethod($this->service, 'ensureProject', [$dirName]);
        $id2 = $this->invokePrivateMethod($this->service, 'ensureProject', [$dirName]);

        $this->assertEquals($id1, $id2);

        // Clean up
        \Illuminate\Support\Facades\DB::table('sessions.projects')->where('id', $id1)->delete();
    }

    public function test_discover_projects_lists_directories(): void
    {
        // Create a temp projects dir with subdirectories
        $projectsDir = $this->tempDir . '/projects';
        mkdir($projectsDir . '/project-a', 0755, true);
        mkdir($projectsDir . '/project-b', 0755, true);
        file_put_contents($projectsDir . '/not-a-dir.txt', 'test');

        // Use reflection to set projectsDir
        $ref = new \ReflectionProperty($this->service, 'projectsDir');
        $ref->setAccessible(true);
        $ref->setValue($this->service, $projectsDir);

        $dirs = $this->invokePrivateMethod($this->service, 'discoverProjects', []);

        $this->assertCount(2, $dirs);
        $this->assertContains('project-a', $dirs);
        $this->assertContains('project-b', $dirs);
    }

    public function test_discover_sessions_lists_jsonl_files(): void
    {
        $dirPath = $this->tempDir . '/test-project';
        mkdir($dirPath, 0755, true);
        file_put_contents($dirPath . '/abc.jsonl', '');
        file_put_contents($dirPath . '/def.jsonl', '');
        file_put_contents($dirPath . '/not-jsonl.txt', '');
        mkdir($dirPath . '/subdir', 0755);

        $files = $this->invokePrivateMethod($this->service, 'discoverSessions', [$dirPath]);

        $this->assertCount(2, $files);
        $this->assertTrue(str_ends_with($files[0], '.jsonl'));
        $this->assertTrue(str_ends_with($files[1], '.jsonl'));
    }

    public function test_format_pg_array(): void
    {
        $result = $this->invokePrivateMethod($this->service, 'formatPgArray', [['Read', 'Bash', 'Edit']]);
        $this->assertEquals('{"Read","Bash","Edit"}', $result);
    }

    public function test_format_pg_array_escapes_special_chars(): void
    {
        $result = $this->invokePrivateMethod($this->service, 'formatPgArray', [['tool "name"', 'back\\slash']]);
        $this->assertEquals('{"tool \\"name\\"","back\\\\slash"}', $result);
    }

    public function test_skips_malformed_json_lines(): void
    {
        $sessionId = 'test-session-' . uniqid();
        $filePath = $this->tempDir . '/bad.jsonl';

        $lines = [
            '{"not valid json',
            json_encode([
                'type' => 'user',
                'uuid' => 'uuid-1',
                'parentUuid' => null,
                'timestamp' => '2026-03-19T14:30:00.000Z',
                'sessionId' => $sessionId,
                'message' => ['role' => 'user', 'content' => 'Hello'],
                'cwd' => '/test',
                'gitBranch' => 'main',
                'version' => '1.0.0',
            ]),
            '', // empty line
        ];

        file_put_contents($filePath, implode("\n", $lines));

        $result = $this->invokePrivateMethod($this->service, 'parseSessionFile', [$filePath]);

        $this->assertEquals($sessionId, $result['metadata']['session_id']);
        $this->assertCount(1, $result['messages']);
    }

    // --- Helpers ---

    private function invokePrivateMethod(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    private function createFixtureFile(string $sessionId): string
    {
        $filePath = $this->tempDir . '/' . $sessionId . '.jsonl';
        $lines = [
            json_encode([
                'type' => 'user',
                'uuid' => 'user-uuid-1',
                'parentUuid' => null,
                'timestamp' => '2026-03-19T14:30:00.000Z',
                'sessionId' => $sessionId,
                'message' => ['role' => 'user', 'content' => 'Hello, can you help?'],
                'cwd' => '/home/dev/projects/test',
                'gitBranch' => 'main',
                'version' => '1.0.0',
                'isSidechain' => false,
            ]),
            json_encode([
                'type' => 'assistant',
                'uuid' => 'asst-uuid-1',
                'parentUuid' => 'user-uuid-1',
                'timestamp' => '2026-03-19T14:30:05.000Z',
                'sessionId' => $sessionId,
                'message' => [
                    'model' => 'claude-opus-4-6',
                    'role' => 'assistant',
                    'usage' => [
                        'input_tokens' => 1500,
                        'output_tokens' => 300,
                        'cache_creation_input_tokens' => 0,
                        'cache_read_input_tokens' => 14000,
                    ],
                    'content' => [
                        ['type' => 'text', 'text' => 'Sure, I can help you with that!'],
                    ],
                ],
                'isSidechain' => false,
            ]),
            json_encode([
                'type' => 'assistant',
                'uuid' => 'asst-uuid-2',
                'parentUuid' => 'asst-uuid-1',
                'timestamp' => '2026-03-19T14:30:10.000Z',
                'sessionId' => $sessionId,
                'message' => [
                    'model' => 'claude-opus-4-6',
                    'role' => 'assistant',
                    'usage' => [
                        'input_tokens' => 200,
                        'output_tokens' => 100,
                        'cache_creation_input_tokens' => 500,
                        'cache_read_input_tokens' => 1000,
                    ],
                    'content' => [
                        ['type' => 'text', 'text' => 'Here is more info.'],
                    ],
                ],
                'isSidechain' => false,
            ]),
        ];

        file_put_contents($filePath, implode("\n", $lines));
        return $filePath;
    }

    private function createFixtureFileWithTools(string $sessionId): string
    {
        $filePath = $this->tempDir . '/' . $sessionId . '.jsonl';
        $lines = [
            json_encode([
                'type' => 'user',
                'uuid' => 'user-uuid-1',
                'parentUuid' => null,
                'timestamp' => '2026-03-19T14:30:00.000Z',
                'sessionId' => $sessionId,
                'message' => ['role' => 'user', 'content' => 'Read my files'],
                'cwd' => '/test',
                'gitBranch' => 'main',
                'version' => '1.0.0',
                'isSidechain' => false,
            ]),
            json_encode([
                'type' => 'assistant',
                'uuid' => 'asst-uuid-1',
                'parentUuid' => 'user-uuid-1',
                'timestamp' => '2026-03-19T14:30:05.000Z',
                'sessionId' => $sessionId,
                'message' => [
                    'model' => 'claude-opus-4-6',
                    'role' => 'assistant',
                    'usage' => [
                        'input_tokens' => 100,
                        'output_tokens' => 50,
                        'cache_creation_input_tokens' => 0,
                        'cache_read_input_tokens' => 0,
                    ],
                    'content' => [
                        ['type' => 'text', 'text' => 'Let me read that.'],
                        ['type' => 'tool_use', 'name' => 'Read', 'id' => 'tool-1', 'input' => ['file_path' => '/test']],
                        ['type' => 'tool_use', 'name' => 'Bash', 'id' => 'tool-2', 'input' => ['command' => 'ls']],
                    ],
                ],
                'isSidechain' => false,
            ]),
        ];

        file_put_contents($filePath, implode("\n", $lines));
        return $filePath;
    }

    private function createFixtureFileWithToolResults(string $sessionId): string
    {
        $filePath = $this->tempDir . '/' . $sessionId . '.jsonl';
        $lines = [
            // Real user message (turn 1)
            json_encode([
                'type' => 'user',
                'uuid' => 'user-uuid-1',
                'parentUuid' => null,
                'timestamp' => '2026-03-19T14:30:00.000Z',
                'sessionId' => $sessionId,
                'message' => ['role' => 'user', 'content' => 'Run a command'],
                'cwd' => '/test',
                'gitBranch' => 'main',
                'version' => '1.0.0',
                'isSidechain' => false,
            ]),
            // Assistant calls a tool
            json_encode([
                'type' => 'assistant',
                'uuid' => 'asst-uuid-1',
                'parentUuid' => 'user-uuid-1',
                'timestamp' => '2026-03-19T14:30:05.000Z',
                'sessionId' => $sessionId,
                'message' => [
                    'model' => 'claude-opus-4-6',
                    'role' => 'assistant',
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
                    'content' => [['type' => 'tool_use', 'name' => 'Bash', 'id' => 'tool-1', 'input' => []]],
                ],
                'isSidechain' => false,
            ]),
            // Tool result user message (NOT a real turn)
            json_encode([
                'type' => 'user',
                'uuid' => 'user-uuid-2',
                'parentUuid' => 'asst-uuid-1',
                'timestamp' => '2026-03-19T14:30:06.000Z',
                'sessionId' => $sessionId,
                'sourceToolAssistantUUID' => 'asst-uuid-1',
                'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'content' => 'output here']]],
                'isSidechain' => false,
            ]),
            // Assistant responds to tool result
            json_encode([
                'type' => 'assistant',
                'uuid' => 'asst-uuid-2',
                'parentUuid' => 'user-uuid-2',
                'timestamp' => '2026-03-19T14:30:10.000Z',
                'sessionId' => $sessionId,
                'message' => [
                    'model' => 'claude-opus-4-6',
                    'role' => 'assistant',
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
                    'content' => [['type' => 'text', 'text' => 'Done!']],
                ],
                'isSidechain' => false,
            ]),
        ];

        file_put_contents($filePath, implode("\n", $lines));
        return $filePath;
    }

    private function createFixtureFileWithFork(string $sessionId): string
    {
        $filePath = $this->tempDir . '/' . $sessionId . '.jsonl';
        $lines = [
            json_encode([
                'type' => 'user',
                'uuid' => 'user-uuid-1',
                'parentUuid' => null,
                'timestamp' => '2026-03-19T14:00:00.000Z',
                'sessionId' => $sessionId,
                'message' => ['role' => 'user', 'content' => 'Hello'],
                'cwd' => '/test',
                'gitBranch' => 'main',
                'version' => '1.0.0',
                'isSidechain' => false,
            ]),
            // First child of user-uuid-1
            json_encode([
                'type' => 'assistant',
                'uuid' => 'asst-uuid-1',
                'parentUuid' => 'user-uuid-1',
                'timestamp' => '2026-03-19T14:00:05.000Z',
                'sessionId' => $sessionId,
                'message' => [
                    'model' => 'claude-opus-4-6',
                    'role' => 'assistant',
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
                    'content' => [['type' => 'text', 'text' => 'Response A']],
                ],
                'isSidechain' => false,
            ]),
            // Second child of user-uuid-1 (fork — 10 minutes later)
            json_encode([
                'type' => 'assistant',
                'uuid' => 'asst-uuid-2',
                'parentUuid' => 'user-uuid-1',
                'timestamp' => '2026-03-19T14:10:05.000Z',
                'sessionId' => $sessionId,
                'message' => [
                    'model' => 'claude-opus-4-6',
                    'role' => 'assistant',
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
                    'content' => [['type' => 'text', 'text' => 'Response B (forked)']],
                ],
                'isSidechain' => false,
            ]),
        ];

        file_put_contents($filePath, implode("\n", $lines));
        return $filePath;
    }

    private function recursiveDelete(string $dir): void
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
