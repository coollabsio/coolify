<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\RunImageBuild;
use App\Models\Node;
use App\Models\NodeWorkload;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Runs one command in a container of a cluster application with `workload.exec.v1` (decision 0018).
 *
 * Sentinel runs the command in the background and every poll returns its state, so a long command does not
 * hold the command queue of the Node. Sentinel only runs commands in workload containers that Coolify manages.
 */
class RunNodeWorkloadCommand
{
    use AsAction;

    public const CAPABILITY = 'workload.exec.v1';

    public const OUTPUT_LIMIT_BYTES = 1024 * 1024;

    private const POLL_INTERVAL_MICROSECONDS = 2_000_000;

    /** Sentinel ends the command at its timeout; this covers a slow Node or a reconnect. */
    private const POLL_MARGIN_SECONDS = 60;

    /**
     * The running containers of the workload, one per Node, from the container inventory.
     *
     * @return Collection<int, array{node: Node, container: string}>
     */
    public static function runningContainers(NodeWorkload $workload): Collection
    {
        $prefix = 'coolify-'.$workload->uuid.'-';

        return $workload->containers()
            ->with('node')
            ->where('state', 'running')
            ->where('name', 'like', $prefix.'%')
            ->orderBy('node_id')
            ->orderByRaw('runtime_created_at DESC NULLS LAST')
            ->get()
            ->filter(fn ($container): bool => $container->node !== null && $container->node->team_id === $workload->team_id)
            ->unique('node_id')
            ->map(fn ($container): array => ['node' => $container->node, 'container' => $container->name])
            ->values();
    }

    /**
     * @param  list<string>  $arguments
     * @return array{exit_code: ?int, output: string, truncated: bool, timed_out: bool, error: string}
     */
    public function handle(Node $node, string $container, array $arguments, int $timeoutSeconds): array
    {
        if ($node->supportsCapability(self::CAPABILITY) !== true) {
            throw new RuntimeException('Upgrade Sentinel on this server to run commands in its containers.');
        }

        $payload = [
            'server_id' => $node->uuid,
            'exec_id' => (string) Str::uuid(),
            'container' => $container,
            'argv' => $arguments,
            'timeout_seconds' => $timeoutSeconds,
        ];
        $deadline = now()->addSeconds($timeoutSeconds + self::POLL_MARGIN_SECONDS);
        $result = $this->poll($payload, $arguments);
        $lastError = null;
        while (data_get($result, 'state') === 'running') {
            if (now()->greaterThan($deadline)) {
                throw new RuntimeException($lastError ?? 'Sentinel did not report the end of the command.');
            }
            usleep(self::POLL_INTERVAL_MICROSECONDS);
            try {
                $result = $this->poll($payload, $arguments);
                $lastError = null;
            } catch (RuntimeException $exception) {
                // The Node can reconnect while the command runs; Sentinel keeps its state.
                $lastError = $exception->getMessage();
            }
        }

        if (! in_array(data_get($result, 'state'), ['succeeded', 'failed'], true)) {
            throw new RuntimeException('Flux returned an invalid command result.');
        }

        return [
            'exit_code' => is_int(data_get($result, 'exit_code')) ? $result['exit_code'] : null,
            'output' => (string) data_get($result, 'output', ''),
            'truncated' => (bool) data_get($result, 'truncated', false),
            'timed_out' => (bool) data_get($result, 'timed_out', false),
            'error' => (string) data_get($result, 'error', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function poll(array $payload, array $arguments): array
    {
        return RunImageBuild::flux('workload.exec', [...$payload, 'command_id' => (string) Str::uuid()], 45, $arguments);
    }
}
