<?php

namespace App\Actions\Node;

use App\Actions\Node\UpdateNodeWorkloadRuntimeOptions as RuntimeOptions;
use App\Enums\NodeContainerSlot;
use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * The pre- and post-deployment commands of a cluster application (decision 0032), with
 * `workload.exec.v1` (decision 0018), once for each deploy, not once for each server.
 *
 * A deploy is the operations of one notification group: a deploy, a rollback, or the deploy of a
 * build that a user or a webhook started. The reconciler, a move, a restore, and the restart of a
 * container by Sentinel run no command, as in v4.
 */
class RunNodeDeploymentCommand
{
    /** The longest wait of a server for the pre-deployment command of another server. */
    public const LOCK_MARGIN_SECONDS = 180;

    /**
     * Runs the pre-deployment command in the running container of the old revision, before the
     * operation changes a container. The first operation of the deploy runs it. The others wait
     * and use its result.
     *
     * @return array<string, mixed> The result to keep in the operation, or [] when there is no command.
     *
     * @throws RuntimeException When the command failed. The old containers keep running.
     */
    public static function pre(NodeOperation $operation): array
    {
        $workload = $operation->workload;
        $command = trim((string) $workload?->pre_deployment_command);
        if ($workload === null || $command === '' || $operation->notification_group === null || data_get($operation->request, 'part_of_move')) {
            return [];
        }
        $recorded = data_get($operation->result, 'pre_deployment_command');
        if (! is_array($recorded)) {
            $timeout = self::timeout($workload);
            $key = 'node-pre-deployment:'.$operation->notification_group;
            $recorded = Cache::lock("{$key}:lock", $timeout + self::LOCK_MARGIN_SECONDS)
                ->block($timeout + self::LOCK_MARGIN_SECONDS, function () use ($key, $workload, $command, $timeout): array {
                    $stored = Cache::get($key);
                    if (is_array($stored)) {
                        // Another server of this deploy ran it. Its log has the output.
                        return [...collect($stored)->except('output')->all(), 'ran_here' => false];
                    }
                    $target = RunNodeWorkloadCommand::runningContainers($workload)->first();
                    $stored = $target === null
                        ? ['status' => 'skipped']
                        : ['server' => $target['node']->name, 'container' => $target['container'], ...self::run($workload, $target['node'], $target['container'], $command, $timeout)];
                    Cache::put($key, $stored, now()->addDay());

                    return [...$stored, 'ran_here' => true];
                });
            $operation->update(['result' => [...($operation->refresh()->result ?? []), 'pre_deployment_command' => $recorded]]);
        }
        if (($recorded['status'] ?? null) === 'failed') {
            throw new RuntimeException(self::failure('pre-deployment', $recorded).' The deploy stopped, and the old containers keep running.');
        }

        return ['pre_deployment_command' => $recorded];
    }

    /**
     * Runs the post-deployment command in the new container of the first server, when every
     * operation of the deploy succeeded. A failure is a warning: the deploy stays successful.
     */
    public static function post(string $notificationGroup): void
    {
        $group = NodeOperation::query()->with(['node', 'workload'])->where('notification_group', $notificationGroup)->orderBy('id')->get();
        $first = $group->first();
        $workload = $first?->workload;
        $command = trim((string) $workload?->post_deployment_command);
        if ($first === null || $workload === null || $command === ''
            || $group->contains(fn (NodeOperation $operation): bool => $operation->status !== NodeOperationStatus::SUCCEEDED)
            || ! Cache::add('node-post-deployment:'.$notificationGroup, true, now()->addDay())) {
            return;
        }
        $slot = NodeContainerSlot::tryFrom((string) data_get($first->request, 'container.slot'));
        $container = $slot?->containerName($workload);
        $result = $container === null || $first->node === null
            ? ['status' => 'failed', 'error' => 'Coolify did not find the new container.']
            : ['server' => $first->node->name, 'container' => $container, ...self::run($workload, $first->node, $container, $command, self::timeout($workload))];
        $first->update(['result' => [...($first->refresh()->result ?? []), 'post_deployment_command' => $result]]);
    }

    /**
     * The lines of the deployment log for the result of a command: what ran where, its output,
     * and the end. A failure of the post-deployment command is a warning.
     *
     * @param  array<string, mixed>  $result  The result of the operation.
     * @param  'pre'|'post'  $kind
     * @return list<array{0: string, 1: bool}> The line and whether it is an error line.
     */
    public static function logLines(array $result, string $kind): array
    {
        $entry = data_get($result, "{$kind}_deployment_command");
        if (! is_array($entry)) {
            return [];
        }
        $label = $kind === 'pre' ? 'pre-deployment' : 'post-deployment';
        $status = $entry['status'] ?? 'failed';
        if ($status === 'skipped') {
            return [["No container of the old revision runs, so the {$label} command was skipped.", false]];
        }
        if (($entry['ran_here'] ?? true) === false) {
            return [["The {$label} command ran with the deploy on server ".($entry['server'] ?? 'another server').': '.$status.'.', $status === 'failed']];
        }
        $lines = [['Running the '.$label.' command in '.($entry['container'] ?? 'the container').' on server '.($entry['server'] ?? 'unknown').'.', false]];
        foreach (preg_split('/\r\n|\r|\n/', rtrim((string) ($entry['output'] ?? ''))) ?: [] as $line) {
            if ($line !== '') {
                $lines[] = [$line, $status === 'failed'];
            }
        }
        if ($entry['truncated'] ?? false) {
            $lines[] = ['The output was cut at 1 MiB.', false];
        }
        $lines[] = $status === 'succeeded'
            ? ["The {$label} command finished.", false]
            : [self::failure($label, $entry).($kind === 'post' ? ' Warning: the deploy stays successful.' : ''), true];

        return $lines;
    }

    /** The text of a failure of a command, for the log and the error of the operation. */
    public static function failure(string $kind, array $result): string
    {
        return match (true) {
            (bool) ($result['timed_out'] ?? false) => "The {$kind} command timed out.",
            filled($result['error'] ?? null) => "The {$kind} command did not run: {$result['error']}",
            default => "The {$kind} command failed with exit code ".($result['exit_code'] ?? 'unknown').'.',
        };
    }

    /** @return array{status: string, exit_code?: ?int, output?: string, truncated?: bool, timed_out?: bool, error?: string} */
    private static function run(NodeWorkload $workload, Node $node, string $container, string $command, int $timeout): array
    {
        try {
            $result = RunNodeWorkloadCommand::run($node, $container, ['sh', '-c', $command], $timeout);
        } catch (Throwable $exception) {
            return ['status' => 'failed', 'error' => $workload->redactLogText(mb_substr($exception->getMessage(), 0, 2000))];
        }
        $succeeded = $result['exit_code'] === 0 && ! $result['timed_out'] && $result['error'] === '';

        return [
            'status' => $succeeded ? 'succeeded' : 'failed',
            'exit_code' => $result['exit_code'],
            'output' => $workload->redactLogText($result['output']),
            'truncated' => $result['truncated'],
            'timed_out' => $result['timed_out'],
            'error' => $workload->redactLogText($result['error']),
        ];
    }

    private static function timeout(NodeWorkload $workload): int
    {
        return max(1, min(RuntimeOptions::MAX_COMMAND_TIMEOUT, (int) ($workload->deployment_command_timeout ?: 300)));
    }
}
