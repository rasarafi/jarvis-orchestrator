<?php // backend/tests/Unit/ClaudeCliServiceTest.php
namespace Tests\Unit;

use App\Exceptions\ClaudeCliException;
use App\Services\ClaudeCliService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ClaudeCliServiceTest extends TestCase
{
    private function envelope(string $result, string $subtype = 'success', bool $isError = false): string
    {
        return json_encode([
            'type' => 'result', 'subtype' => $subtype, 'is_error' => $isError,
            'result' => $result, 'session_id' => 'cli-run-1', 'total_cost_usd' => 0.002,
        ]);
    }

    public function test_parses_json_result_and_strips_fences(): void
    {
        Process::fake(['*' => Process::result(output: $this->envelope("```json\n{\"headline\": \"Fixed bug\", \"bullets\": []}\n```"))]);

        $out = app(ClaudeCliService::class)->promptForJson('[JARVIS-RECAP] summarize', 'haiku');

        $this->assertSame('Fixed bug', $out['headline']);
        Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), '--model haiku')
            && str_contains(implode(' ', $process->command), '--output-format json')
            && str_starts_with($process->input, '[JARVIS-RECAP]'));
    }

    public function test_throws_on_error_envelope(): void
    {
        Process::fake(['*' => Process::result(output: $this->envelope('overloaded', 'error_during_execution', true))]);
        $this->expectException(ClaudeCliException::class);
        app(ClaudeCliService::class)->promptForJson('[JARVIS-RECAP] x', 'haiku');
    }

    public function test_throws_on_nonzero_exit(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);
        $this->expectException(ClaudeCliException::class);
        app(ClaudeCliService::class)->promptForJson('[JARVIS-RECAP] x', 'haiku');
    }

    public function test_throws_on_unparseable_result_json(): void
    {
        Process::fake(['*' => Process::result(output: $this->envelope('not json at all'))]);
        $this->expectException(ClaudeCliException::class);
        app(ClaudeCliService::class)->promptForJson('[JARVIS-RECAP] x', 'haiku');
    }

    public function test_lean_flags_present_by_default(): void
    {
        Process::fake(['*' => Process::result(output: $this->envelope('{"headline":"x","bullets":[]}'))]);

        app(ClaudeCliService::class)->promptForJson('[JARVIS-RECAP] x', 'haiku');

        Process::assertRan(function ($process) {
            $cmd = $process->command;
            return in_array('--setting-sources=', $cmd, true)
                && in_array('--max-turns', $cmd, true)
                && in_array('--allowedTools', $cmd, true);
        });
    }

    public function test_lean_flags_omitted_when_disabled(): void
    {
        config(['services.claude.lean' => false]);
        Process::fake(['*' => Process::result(output: $this->envelope('{"headline":"x","bullets":[]}'))]);

        app(ClaudeCliService::class)->promptForJson('[JARVIS-RECAP] x', 'haiku');

        Process::assertRan(fn ($process) => ! in_array('--setting-sources=', $process->command, true));
    }

    public function test_batch_returns_decoded_results_keyed_by_input(): void
    {
        Process::fake(['*' => Process::result(output: $this->envelope('{"headline":"Batched","bullets":["a"]}'))]);

        $out = app(ClaudeCliService::class)->promptForJsonBatch(
            ['s1' => '[JARVIS-RECAP] one', 's2' => '[JARVIS-RECAP] two'],
            'haiku',
        );

        $this->assertSame(['s1', 's2'], array_keys($out));
        $this->assertTrue($out['s1']['ok']);
        $this->assertTrue($out['s2']['ok']);
        $this->assertSame('Batched', $out['s1']['data']['headline']);
        $this->assertSame('Batched', $out['s2']['data']['headline']);
        Process::assertRanTimes(fn ($process) => str_contains(implode(' ', $process->command), '--model haiku'), 2);
    }

    public function test_batch_marks_failed_items_without_throwing(): void
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'boom', exitCode: 1)]);

        $out = app(ClaudeCliService::class)->promptForJsonBatch(['s1' => '[JARVIS-RECAP] one'], 'haiku');

        $this->assertFalse($out['s1']['ok']);
        $this->assertNull($out['s1']['data']);
        $this->assertStringContainsString('exited 1', $out['s1']['error']);
    }

    public function test_batch_returns_empty_for_no_prompts(): void
    {
        $this->assertSame([], app(ClaudeCliService::class)->promptForJsonBatch([], 'haiku'));
    }

    public function test_batch_isolates_a_single_item_failure_from_the_rest(): void
    {
        // One process fails; the batch must still return a complete keyed result with the
        // other items ok=true — one bad session can't sink the whole concurrent batch.
        Process::fake(['*' => Process::sequence()
            ->push(Process::result(output: '', errorOutput: 'boom', exitCode: 1))
            ->push(Process::result(output: $this->envelope('{"headline":"Good","bullets":[]}')))
            ->push(Process::result(output: $this->envelope('{"headline":"Good","bullets":[]}'))),
        ]);

        $out = app(ClaudeCliService::class)->promptForJsonBatch(
            ['s1' => '[JARVIS-RECAP] a', 's2' => '[JARVIS-RECAP] b', 's3' => '[JARVIS-RECAP] c'],
            'haiku',
        );

        $this->assertSame(['s1', 's2', 's3'], array_keys($out));
        $this->assertSame(1, collect($out)->where('ok', false)->count());
        $this->assertSame(2, collect($out)->where('ok', true)->count());
    }
}
