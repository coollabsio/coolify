<?php

namespace App\Actions\Node\Build;

use App\Actions\Node\CreateOperation;
use App\Actions\Node\TransitionOperation;
use App\Enums\NodeBuildStatus;
use App\Enums\NodeOperationStatus;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeContainer;
use App\Models\NodeOperation;
use App\Models\NodeWorkloadRevision;
use App\Support\NodeImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Copies the local image of a revision (`localhost/<workload>:<build>`) to a Node that must run it,
 * from another Node of the cluster that has it (decision 0030). The source serves the image on its
 * WireGuard address to the target only (`image.serve`), and the target pulls the layers that it does
 * not have (`image.fetch`). The sources are the build Node, then the other Nodes that ran the image.
 * Runs in a queue job: a fetch can take minutes.
 */
class FetchNodeImage
{
    use AsAction;

    /** The longest fetch when the caller gives no deadline. Keeps a deploy job below the stale operation limit. */
    public const MAX_SECONDS = 600;

    public const POLL_SECONDS = 2;

    /** The operation of one copy attempt, on the target (decision 0030). */
    public const COMMAND = 'image.copy.v1';

    /** How often the progress of a copy is written for the deploy log. */
    private const PROGRESS_SECONDS = 4;

    /** How long the source serves the image when the transfer is not stopped. */
    private const SERVE_MINUTES = 30;

    /**
     * Why a move of a local image from the source to the target cannot run, or null when it can. A move
     * copies the image from the source (decision 0030), so the source must be online: a recovery move
     * from an offline server cannot get it.
     */
    public static function moveBlockedReason(Node $source, Node $target, ?string $image, bool $recovery): ?string
    {
        if (! NodeImages::isLocalImage($image) || $source->id === $target->id) {
            return null;
        }
        if ($recovery) {
            return "The image is only on {$source->name}, which is offline. Deploy the application again, then recover it.";
        }
        $old = collect([
            $source->supportsCapability(NodeImages::SERVE_CAPABILITY) === true ? null : $source->name,
            $target->supportsCapability(NodeImages::FETCH_CAPABILITY) === true ? null : $target->name,
        ])->filter()->unique()->implode(' and ');
        if ($old !== '') {
            return "The image is only on {$source->name}, and Sentinel on {$old} cannot copy it. Upgrade Sentinel on {$old}, or deploy the application again, then move it.";
        }

        return null;
    }

    /**
     * Returns the copy: the source Node, the seconds it took, the uncompressed size of the image, and
     * the bytes that the source sent (the layers that the target did not have). Null when nothing had
     * to be copied: the image is not local, or a container of it is on the target.
     *
     * @return array{source: Node, seconds: float, size_bytes: int, bytes_sent: int}|null
     *
     * Each attempt is an `image.copy.v1` operation on the target, for the Activity page of both
     * servers and the live progress in the log of `$for`, the deploy that needs the image.
     *
     * @throws RuntimeException When no source could copy the image.
     */
    public function handle(Node $target, NodeWorkloadRevision $revision, ?Carbon $deadline = null, ?NodeOperation $for = null): ?array
    {
        $image = $revision->image;
        if (! NodeImages::isLocalImage($image)) {
            return null;
        }
        $build = $this->build($revision);
        $revisionIds = NodeWorkloadRevision::query()
            ->where('node_workload_id', $revision->node_workload_id)
            ->where('image', $image)
            ->pluck('id');
        $ranOnTarget = $this->ranOn($target, $revisionIds);
        if (NodeContainer::query()->where('node_id', $target->id)->whereIn('node_workload_revision_id', $revisionIds)->exists()) {
            // A container of the image is on the target, so Podman keeps the image there. Without
            // one, the image can be gone (a prune or a manual removal): the copy then checks it, and
            // Sentinel succeeds at once when the target has the image.
            return null;
        }
        $deadline ??= now()->addSeconds(self::MAX_SECONDS);
        $sources = $this->sources($target, $revisionIds, $build);
        if ($sources->isEmpty() && ($ranOnTarget || $build?->node_id === $target->id)) {
            // The target built or ran the image and no other server has it: it may still have it. The
            // deploy shows the error of Podman otherwise.
            return null;
        }
        if ($target->supportsCapability(NodeImages::FETCH_CAPABILITY) !== true || blank($target->wireguard_ip)) {
            throw new RuntimeException("The image of this revision is not on server {$target->name}, and {$target->name} cannot copy it from another server. Upgrade Sentinel on {$target->name}, or deploy the application again to build it on this server.");
        }

        $errors = [];
        foreach ($sources as $source) {
            if (now()->gte($deadline)) {
                $errors[] = 'The time for the copy ran out.';
                break;
            }
            $attempt = $this->startAttempt($source, $target, $revision, $for);
            try {
                $started = microtime(true);
                $copy = $this->fetchFrom($source, $target, $image, $deadline, $attempt);
                $copy = ['seconds' => round(microtime(true) - $started, 1), ...$copy];
                rescue(fn () => TransitionOperation::run($attempt, NodeOperationStatus::SUCCEEDED, result: ['source' => $source->name, ...$copy]), report: false);

                return ['source' => $source, ...$copy];
            } catch (Throwable $exception) {
                $errors[] = "{$source->name}: ".mb_substr($exception->getMessage(), 0, 500);
                rescue(fn () => TransitionOperation::run($attempt, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000)), report: false);
            }
        }

        $rebuild = $revision->workload?->buildsFromSource() ? ' Deploy the application again to build it on this server.' : '';
        $reason = $errors === [] ? ' No online server of the cluster has it.' : ' Copies failed: '.implode(' ', $errors);

        throw new RuntimeException("The image {$image} is not on server {$target->name}.{$reason}{$rebuild}");
    }

    /** The successful build of the image, also for a rollback revision that reuses it. */
    private function build(NodeWorkloadRevision $revision): ?NodeBuild
    {
        return $revision->build ?? NodeBuild::query()
            ->where('node_workload_id', $revision->node_workload_id)
            ->where('image', $revision->image)
            ->where('status', NodeBuildStatus::SUCCEEDED)
            ->latest('id')
            ->first();
    }

    /**
     * The Nodes that can serve the image, in order: the build Node, then the Nodes that ran a revision
     * with the image. Only active Nodes of the cluster and team of the target that report `image.serve.v1`.
     *
     * @param  Collection<int, int>  $revisionIds  The revisions of the workload with the image.
     * @return Collection<int, Node>
     */
    private function sources(Node $target, Collection $revisionIds, ?NodeBuild $build): Collection
    {
        if ($target->node_cluster_id === null) {
            return collect();
        }
        $ran = $this->succeededDeploys($revisionIds)
            ->latest('id')
            ->pluck('node_id')
            ->merge(NodeContainer::query()->whereIn('node_workload_revision_id', $revisionIds)->pluck('node_id'));
        $ids = collect([$build?->node_id])->merge($ran)->filter()->unique()->reject(fn (int $id): bool => $id === $target->id)->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $nodes = Node::query()
            ->whereKey($ids->all())
            ->where('team_id', $target->team_id)
            ->where('node_cluster_id', $target->node_cluster_id)
            ->get()
            ->keyBy('id');

        return $ids->map(fn (int $id): ?Node => $nodes->get($id))
            ->filter(fn (?Node $node): bool => $node !== null
                && $node->canReceiveNetworkCommands()
                && $node->supportsCapability(NodeImages::SERVE_CAPABILITY) === true)
            ->values();
    }

    /** @param  Collection<int, int>  $revisionIds */
    private function ranOn(Node $node, Collection $revisionIds): bool
    {
        return $this->succeededDeploys($revisionIds)->where('node_id', $node->id)->exists();
    }

    /**
     * @param  Collection<int, int>  $revisionIds
     * @return Builder<NodeOperation>
     */
    private function succeededDeploys(Collection $revisionIds): Builder
    {
        return NodeOperation::query()
            ->whereIn('node_workload_revision_id', $revisionIds)
            ->whereIn('command_type', ['workload.deploy.v1', 'workload.move.v1'])
            ->where('status', NodeOperationStatus::SUCCEEDED);
    }

    /**
     * The operation of one copy attempt, running, linked to the deploy that needs the image. A
     * coordinated operation: Coolify runs it here and never sends it to Sentinel as one command.
     */
    private function startAttempt(Node $source, Node $target, NodeWorkloadRevision $revision, ?NodeOperation $for): NodeOperation
    {
        $attempt = CreateOperation::run(
            $target,
            self::COMMAND,
            'image-copy:'.Str::uuid(),
            $revision->workload,
            $revision,
            [
                'source_node_uuid' => $source->uuid,
                'image' => $revision->image,
                ...($for === null ? [] : ['for_operation_uuid' => $for->uuid]),
            ],
            $for?->requestedBy,
        );
        TransitionOperation::run($attempt, NodeOperationStatus::DISPATCHED);
        $attempt = TransitionOperation::run($attempt, NodeOperationStatus::RUNNING, result: ['source' => $source->name]);
        if ($for !== null) {
            $for->update(['result' => [...($for->refresh()->result ?? []), 'image_copy_operation_uuid' => $attempt->uuid]]);
        }

        return $attempt;
    }

    /** @return array{size_bytes: int, bytes_sent: int} */
    private function fetchFrom(Node $source, Node $target, string $image, Carbon $deadline, NodeOperation $attempt): array
    {
        $transferId = (string) Str::uuid();
        $secret = Str::random(64);
        $copy = ['size_bytes' => 0, 'bytes_sent' => 0];
        $serve = [
            'server_id' => $source->uuid,
            'transfer_id' => $transferId,
            'image' => $image,
            'peer_ip' => (string) $target->wireguard_ip,
            'secret' => $secret,
            'expires_at_unix_ms' => now()->addMinutes(self::SERVE_MINUTES)->getTimestampMs(),
            'stop' => false,
        ];
        try {
            $served = RunImageBuild::flux('image.serve', ['command_id' => (string) Str::uuid(), ...$serve], 660, [$secret]);
            $service = Validator::make($served, [
                'address' => ['required', 'ip'],
                'port' => ['required', 'integer', 'between:1,65535'],
                'repository' => ['required', 'string', 'max:255'],
                'digest' => ['required', 'string', 'regex:/^sha256:[0-9a-f]{64}$/'],
                'image_id' => ['required', 'string', 'max:255'],
            ]);
            if ($service->fails()) {
                throw new RuntimeException('Flux returned an invalid image service.');
            }
            $service = $service->validated();
            $copy['size_bytes'] = (int) data_get($served, 'size_bytes', 0);
            $this->progress($attempt, $source, $copy['size_bytes'], 0);
            $progressAt = microtime(true);

            while (true) {
                $state = RunImageBuild::flux('image.fetch', [
                    'server_id' => $target->uuid,
                    'command_id' => (string) Str::uuid(),
                    'transfer_id' => $transferId,
                    'source_address' => $service['address'],
                    'source_port' => (int) $service['port'],
                    'secret' => $secret,
                    'repository' => $service['repository'],
                    'digest' => $service['digest'],
                    'image' => $image,
                    'image_id' => $service['image_id'],
                ], 60, [$secret]);
                $status = data_get($state, 'state');
                if ($status === 'succeeded') {
                    if (data_get($state, 'image_id') !== $service['image_id']) {
                        throw new RuntimeException('The copied image has another image ID than on the source.');
                    }

                    break;
                }
                if ($status === 'failed') {
                    throw new RuntimeException(trim((string) data_get($state, 'error')) ?: 'The copy failed.');
                }
                if ($status !== 'running') {
                    throw new RuntimeException('Flux returned an invalid image copy state.');
                }
                if (now()->gte($deadline)) {
                    throw new RuntimeException('The copy did not finish in time.');
                }
                if (microtime(true) - $progressAt >= self::PROGRESS_SECONDS) {
                    // A repeated serve of the same transfer returns the bytes sent so far.
                    $progressAt = microtime(true);
                    $sent = rescue(fn () => data_get(RunImageBuild::flux('image.serve', ['command_id' => (string) Str::uuid(), ...$serve], 30, [$secret]), 'bytes_sent'), report: false);
                    if (is_numeric($sent)) {
                        $this->progress($attempt, $source, $copy['size_bytes'], (int) $sent);
                    }
                }
                Sleep::for(self::POLL_SECONDS)->seconds();
            }
        } finally {
            // The stop returns the bytes that the source sent: only the layers that the target did not have.
            $stopped = rescue(fn () => RunImageBuild::flux('image.serve', [
                'server_id' => $source->uuid,
                'command_id' => (string) Str::uuid(),
                'transfer_id' => $transferId,
                'stop' => true,
            ], 30));
            $copy['bytes_sent'] = (int) data_get($stopped, 'bytes_sent', 0);
        }

        return $copy;
    }

    private function progress(NodeOperation $attempt, Node $source, int $size, int $sent): void
    {
        $attempt->update(['result' => ['source' => $source->name, 'size_bytes' => $size, 'bytes_sent' => $sent]]);
    }
}
