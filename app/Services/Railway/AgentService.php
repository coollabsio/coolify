<?php

namespace App\Services\Railway;

use App\Models\Environment;
use App\Support\RailwayResourceMapper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

/**
 * The Railway assistant's agent loop. Talks to the Anthropic Messages API over
 * raw HTTP (keeps the feature self-contained — no SDK dependency added to the
 * fork), runs a tool-use loop against {@see AgentTools}, and pauses whenever a
 * mutating tool is requested so the UI can ask the user to confirm.
 *
 * Return shapes from converse()/continueAfterConfirm():
 *   ['type' => 'final',   'text' => string, 'messages' => array]
 *   ['type' => 'confirm', 'text' => string, 'pending' => array, 'assistant' => array,
 *                         'read_results' => array, 'messages' => array]
 *   ['type' => 'error',   'text' => string, 'messages' => array]
 */
final class AgentService
{
    private readonly AgentTools $tools;

    public function __construct(private readonly Environment $environment)
    {
        $this->tools = new AgentTools($environment);
    }

    public static function isConfigured(): bool
    {
        return filled(config('railway_agent.api_key'));
    }

    /**
     * Run the loop from the current message history until the model produces a
     * final answer or requests a mutating tool that needs confirmation.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    public function converse(array $messages): array
    {
        $maxSteps = max(1, (int) config('railway_agent.max_steps', 8));

        for ($step = 0; $step < $maxSteps; $step++) {
            $response = $this->callApi($messages);
            if (! ($response['ok'] ?? false)) {
                return ['type' => 'error', 'text' => $response['error'], 'messages' => $messages];
            }

            $content = $response['content'];
            $stop = $response['stop_reason'] ?? null;

            if ($stop !== 'tool_use') {
                $messages[] = ['role' => 'assistant', 'content' => $content];

                return ['type' => 'final', 'text' => $this->textOf($content), 'messages' => $messages];
            }

            // Split tool calls into safe reads (run now) and writes (need confirm).
            $reads = [];
            $writes = [];
            foreach ($content as $block) {
                if (($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }
                if ($this->tools->isWrite($block['name'])) {
                    $writes[] = $block;
                } else {
                    $reads[] = $block;
                }
            }

            $readResults = array_map(fn ($b) => $this->runTool($b), $reads);

            if ($writes === []) {
                $messages[] = ['role' => 'assistant', 'content' => $content];
                $messages[] = ['role' => 'user', 'content' => $readResults];

                continue;
            }

            // A mutating action was requested — surface it for confirmation.
            $pending = array_map(fn ($b) => [
                'id' => $b['id'],
                'name' => $b['name'],
                'input' => $b['input'] ?? [],
                'summary' => $this->tools->summarize($b['name'], $b['input'] ?? []),
            ], $writes);

            return [
                'type' => 'confirm',
                'text' => $this->textOf($content),
                'pending' => $pending,
                'assistant' => $content,
                'read_results' => $readResults,
                'messages' => $messages,
            ];
        }

        return ['type' => 'error', 'text' => 'The assistant hit its step limit for this turn.', 'messages' => $messages];
    }

    /**
     * Resume the loop after the user approved/denied pending write actions.
     *
     * @param  array<int, array<string, mixed>>  $messages  history up to (not including) the assistant turn
     * @param  array<int, array<string, mixed>>  $assistant  the assistant content blocks that requested the writes
     * @param  array<int, array<string, mixed>>  $readResults  tool_result blocks already computed for reads in that turn
     * @param  array<int, array<string, mixed>>  $pending  the pending write descriptors
     * @param  array<string, bool>  $approved  tool_use_id => approved?
     * @return array<string, mixed>
     */
    public function continueAfterConfirm(array $messages, array $assistant, array $readResults, array $pending, array $approved): array
    {
        $toolResults = $readResults;

        foreach ($pending as $write) {
            $id = $write['id'];
            if ($approved[$id] ?? false) {
                $result = $this->tools->execute($write['name'], $write['input'] ?? []);
                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $id,
                    'content' => $result['content'],
                    'is_error' => $result['is_error'],
                ];
            } else {
                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $id,
                    'content' => 'The user declined to run this action.',
                ];
            }
        }

        $messages[] = ['role' => 'assistant', 'content' => $assistant];
        $messages[] = ['role' => 'user', 'content' => $toolResults];

        return $this->converse($messages);
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function runTool(array $block): array
    {
        $result = $this->tools->execute($block['name'], $block['input'] ?? []);

        return [
            'type' => 'tool_result',
            'tool_use_id' => $block['id'],
            'content' => $result['content'],
            'is_error' => $result['is_error'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array{ok: bool, content?: array, stop_reason?: ?string, error?: string}
     */
    private function callApi(array $messages): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) config('railway_agent.api_key'),
                'anthropic-version' => (string) config('railway_agent.anthropic_version'),
                'content-type' => 'application/json',
            ])
                ->timeout((int) config('railway_agent.timeout', 120))
                ->post((string) config('railway_agent.endpoint'), [
                    'model' => (string) config('railway_agent.model'),
                    'max_tokens' => (int) config('railway_agent.max_tokens', 2048),
                    'system' => $this->systemPrompt(),
                    'tools' => $this->tools->definitions(),
                    'messages' => $messages,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not reach the model: '.$e->getMessage()];
        }

        if ($response->failed()) {
            $message = (string) $response->json('error.message', $response->body());

            return ['ok' => false, 'error' => 'Model API error ('.$response->status().'): '.$message];
        }

        return [
            'ok' => true,
            'content' => $response->json('content', []),
            'stop_reason' => $response->json('stop_reason'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    private function textOf(array $content): string
    {
        return collect($content)
            ->where('type', 'text')
            ->map(fn ($b) => (string) ($b['text'] ?? ''))
            ->filter()
            ->implode("\n");
    }

    private function systemPrompt(): string
    {
        $inventory = RailwayResourceMapper::resourcesFor($this->environment)
            ->map(fn (Model $r) => sprintf('- %s [%s] status=%s', $r->name, RailwayResourceMapper::kind($r), (string) ($r->status ?? 'unknown') ?: 'unknown'))
            ->implode("\n");

        if ($inventory === '') {
            $inventory = '(no resources yet)';
        }

        $projectName = $this->environment->project?->name ?? 'Project';

        return <<<PROMPT
        You are the assistant embedded in a Railway-style UI for Coolify, a self-hostable PaaS.
        You help the user inspect and operate the resources in ONE environment.

        Current context:
        Project: {$projectName}
        Environment: {$this->environment->name}
        Resources:
        {$inventory}

        Guidelines:
        - Be concise and direct. Lead with the answer.
        - Use tools to read live data — never invent service names, statuses, URLs, or logs.
        - Refer to resources by the names shown above.
        - deploy_service and set_env_var change real infrastructure. Call them when the user asks,
          but the UI will show the user a confirmation before anything runs — you do not need to ask
          for permission yourself. If an action comes back declined, acknowledge it and stop.
        - To explain a failed deployment: fetch its logs, identify the root cause from the output,
          and give a specific, actionable fix.
        - This environment is the only thing you can see or touch.
        PROMPT;
    }
}
