<?php
namespace App\Services;

use App\Exceptions\ClaudeCliException;
use Illuminate\Support\Facades\Process;

class ClaudeCliService
{
    /**
     * Run a headless claude -p prompt and return the decoded JSON the model replied with.
     * $model is a CLI alias (haiku|sonnet|opus).
     */
    public function promptForJson(string $prompt, string $model): array
    {
        $pending = Process::timeout(config('services.claude.timeout'))->input($prompt);
        if ($env = $this->env()) {
            $pending = $pending->env($env);
        }
        $result = $pending->run($this->command($model));

        return $this->parseResult($result->successful(), $result->output(), $result->exitCode(), $result->errorOutput());
    }

    /**
     * Run many prompts concurrently (one claude -p process each) and return per-key results.
     * Each entry is ['ok' => bool, 'data' => array|null, 'error' => string|null]. Never throws;
     * a per-item CLI/parse failure is reported as ok=false so one bad session can't sink the batch.
     *
     * @param  array<string, string>  $prompts  keyed by an arbitrary id (e.g. cc_session_id)
     * @return array<string, array{ok: bool, data: array|null, error: string|null}>
     */
    public function promptForJsonBatch(array $prompts, string $model): array
    {
        if ($prompts === []) {
            return [];
        }

        $command = $this->command($model);
        $timeout = config('services.claude.timeout');
        $env = $this->env();

        // Start every process asynchronously (true concurrency), then wait on each one
        // in its OWN try/catch. We deliberately do NOT use Process::pool()->wait(): that
        // maps ->wait() over all processes with no per-process isolation, so a single
        // ProcessTimedOutException aborts the whole map and discards every result —
        // turning one slow session into total batch loss + an uncaught 500. Waiting each
        // process individually and catching \Throwable (timeout OR parse failure) keeps a
        // slow/broken session from sinking the ones that already succeeded.
        $running = [];
        foreach ($prompts as $key => $prompt) {
            $pending = Process::timeout($timeout)->input($prompt);
            if ($env) {
                $pending = $pending->env($env);
            }
            $running[$key] = $pending->start($command);
        }

        $out = [];
        foreach ($running as $key => $process) {
            try {
                $result = $process->wait();
                $out[$key] = [
                    'ok' => true,
                    'data' => $this->parseResult($result->successful(), $result->output(), $result->exitCode(), $result->errorOutput()),
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                $out[$key] = ['ok' => false, 'data' => null, 'error' => $e->getMessage()];
            }
        }

        return $out;
    }

    /**
     * Build the claude CLI argv. In lean mode (default) we strip every setting source
     * (no MCP servers, skills, project CLAUDE.md, or hooks) and cap the run at a single
     * turn with no tools — a summarizer needs none of that, and skipping the agent
     * bootstrap is ~5x faster per call. Auth still applies (it is not a setting source).
     */
    private function command(string $model): array
    {
        $command = [
            config('services.claude.binary'),
            '-p',
            '--model', $model,
            '--output-format', 'json',
        ];

        if (config('services.claude.lean', true)) {
            $command[] = '--setting-sources=';
            $command[] = '--allowedTools';
            $command[] = '';
            $command[] = '--max-turns';
            $command[] = '1';
        }

        return $command;
    }

    /** Optional CLAUDE_CONFIG_DIR pin (which CC account runs recaps). */
    private function env(): array
    {
        return ($dir = config('services.claude.recap_config_dir'))
            ? ['CLAUDE_CONFIG_DIR' => $dir]
            : [];
    }

    /** Parse the CLI's JSON envelope into the model's decoded reply, or throw ClaudeCliException. */
    private function parseResult(bool $successful, string $output, int $exitCode, string $errorOutput): array
    {
        if (! $successful) {
            throw new ClaudeCliException("claude CLI exited {$exitCode}: ".substr($errorOutput, 0, 500));
        }

        $envelope = json_decode($output, true);
        if (! is_array($envelope)) {
            throw new ClaudeCliException('claude CLI produced non-JSON output: '.substr($output, 0, 300));
        }
        if (($envelope['is_error'] ?? false) || ($envelope['subtype'] ?? '') !== 'success') {
            throw new ClaudeCliException('claude CLI error envelope: '.substr((string) ($envelope['result'] ?? ''), 0, 500));
        }

        $decoded = json_decode($this->stripFences((string) ($envelope['result'] ?? '')), true);
        if (! is_array($decoded)) {
            throw new ClaudeCliException('Model reply was not valid JSON: '.substr((string) ($envelope['result'] ?? ''), 0, 300));
        }

        return $decoded;
    }

    private function stripFences(string $text): string
    {
        $text = trim($text);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m)) {
            return $m[1];
        }

        return $text;
    }
}
