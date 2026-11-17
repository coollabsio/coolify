<?php

namespace App\Actions\Node;

use App\Enums\NodeBuildStatus;
use App\Enums\NodeOperationStatus;
use App\Jobs\CleanupNodeImagesJob;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\NodeWorkloadSetEntry;
use App\Models\User;
use App\Support\NodeImages;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * Removes the images on a Node that no container uses, except the images of the last revisions of
 * its workloads, the recent builds of a build Node, and the pinned infrastructure images (decision
 * 0027). Sentinel keeps the BuildKit image itself. Each run is a Node operation with the result of Sentinel. A run waits while a
 * build or a deploy runs on the Node.
 */
class CleanupNodeImages
{
    use AsAction;

    public const COMMAND_TYPE = 'image.prune.v1';

    /** Rollback revisions before the deployed one, like the v4 default of kept rollback images. */
    public const KEPT_EARLIER_REVISIONS = 2;

    /** The threshold run starts again at most once in this time, also when it freed too little. */
    public const THRESHOLD_COOLDOWN_MINUTES = 60;

    /** Operations that pull, load, or start images. A cleanup waits for them. */
    private const BUSY_COMMANDS = ['workload.deploy.v1', 'workload.move.v1', 'workload.lifecycle.v1'];

    private const ACTIVE_STATUSES = [
        NodeOperationStatus::QUEUED,
        NodeOperationStatus::DISPATCHED,
        NodeOperationStatus::RUNNING,
        NodeOperationStatus::VERIFYING,
        NodeOperationStatus::UNCERTAIN,
    ];

    /**
     * Queues a cleanup, or returns the cleanup that is already queued or runs on the Node.
     *
     * @param  string  $trigger  `manual`, `schedule`, or `threshold`.
     */
    public static function start(Node $node, ?User $requestedBy, string $trigger): NodeOperation
    {
        $operation = self::queue($node, $requestedBy, $trigger);
        if ($operation->wasRecentlyCreated) {
            CleanupNodeImagesJob::dispatch($operation->id);
        }

        return $operation;
    }

    /** Creates the cleanup operation without a job, or returns the active one. */
    public static function queue(Node $node, ?User $requestedBy, string $trigger): NodeOperation
    {
        $node->ensureReportedCapability(self::COMMAND_TYPE);

        return DB::transaction(function () use ($node, $requestedBy, $trigger): NodeOperation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            $active = $node->operations()
                ->where('command_type', self::COMMAND_TYPE)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->latest('id')
                ->first();

            return $active ?? CreateOperation::run(
                $node,
                self::COMMAND_TYPE,
                'image-prune:'.Str::uuid(),
                request: ['trigger' => $trigger],
                requestedBy: $requestedBy,
            );
        });
    }

    /** Starts a cleanup when the disk use that Sentinel reported is at or above the threshold of the Node. */
    public static function startWhenAboveThreshold(Node $node): ?NodeOperation
    {
        $usage = self::diskUsagePercent($node);
        $threshold = (int) $node->image_cleanup_threshold;
        if ($usage === null || $threshold < 1 || $usage < $threshold || $node->supportsCapability(self::COMMAND_TYPE) !== true) {
            return null;
        }
        if (! Cache::add("node-image-cleanup:threshold:{$node->id}", true, now()->addMinutes(self::THRESHOLD_COOLDOWN_MINUTES))) {
            return null;
        }

        return self::start($node, null, 'threshold');
    }

    /** The used part of the root disk in percent, from the last `system.info` of the Node. */
    public static function diskUsagePercent(Node $node): ?float
    {
        $total = data_get($node->metadata, 'disk_total_bytes');
        $available = data_get($node->metadata, 'disk_available_bytes');
        if (! is_numeric($total) || ! is_numeric($available) || (float) $total <= 0) {
            return null;
        }

        return round(100 * (1 - ((float) $available / (float) $total)), 1);
    }

    /**
     * The images that the cleanup keeps: for each workload that has or had a placement on the Node,
     * the revision that runs there, the 2 revisions before it, and the newest revision. On a build Node,
     * the local images of the last 3 builds of each workload, also when no workload runs there: other
     * Nodes copy them from there for a deploy, a move, or a rollback (decision 0030). Then the pinned
     * builder image and the Sentinel host image of the last install. Sentinel with
     * `workload.reconcile.v1` keeps the images of its desired set itself (decision 0026), so the
     * revision of a set entry is not in the list; its earlier revisions stay for a rollback.
     *
     * @return list<string>
     */
    public static function keepList(Node $node): array
    {
        $workloadIds = NodeWorkload::query()
            ->withoutGlobalScopes()
            ->where('team_id', $node->team_id)
            ->where(fn ($query) => $query
                ->whereHas('nodes', fn ($nodes) => $nodes->whereKey($node->id))
                ->orWhereIn('id', $node->operations()->whereNotNull('node_workload_id')->select('node_workload_id')))
            ->pluck('id');

        $inSet = $node->supportsCapability(SyncNodeWorkloadSet::CAPABILITY) === true
            ? NodeWorkloadSetEntry::query()->where('node_id', $node->id)->pluck('node_workload_revision_id', 'node_workload_id')
            : collect();
        $keep = collect();
        foreach ($workloadIds as $workloadId) {
            $deployedRevisionId = $node->operations()
                ->where('node_workload_id', $workloadId)
                ->whereIn('command_type', ['workload.deploy.v1', 'workload.move.v1'])
                ->where('status', NodeOperationStatus::SUCCEEDED)
                ->whereNotNull('node_workload_revision_id')
                ->latest('id')
                ->value('node_workload_revision_id');
            $revisions = NodeWorkloadRevision::query()->where('node_workload_id', $workloadId)->whereNotNull('image')
                ->when($inSet->has($workloadId), fn ($query) => $query->whereKeyNot($inSet->get($workloadId)));
            $keep->push((clone $revisions)->latest('id')->value('image'));
            if ($deployedRevisionId !== null) {
                $keep = $keep->merge((clone $revisions)
                    ->where('id', '<=', $deployedRevisionId)
                    ->latest('id')
                    ->limit(self::KEPT_EARLIER_REVISIONS + 1)
                    ->pluck('image'));
            }
        }

        $builds = NodeBuild::query()
            ->where('node_id', $node->id)
            ->where('status', NodeBuildStatus::SUCCEEDED)
            ->where('image', 'like', NodeImages::LOCAL_PREFIX.'%')
            ->latest('id')
            ->get(['id', 'node_workload_id', 'image']);
        $keep = $keep->merge($builds->groupBy('node_workload_id')
            ->flatMap(fn ($workloadBuilds) => $workloadBuilds->take(self::KEPT_EARLIER_REVISIONS + 1)->pluck('image')));

        $keep->push(config('constants.node.builder_image'));
        $keep->push(self::sentinelHostImage($node));

        return $keep->filter(fn ($image): bool => is_string($image) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,511}\z/', $image) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /** The image of the last Sentinel install or upgrade of the Node, or the configured install image. */
    private static function sentinelHostImage(Node $node): ?string
    {
        $image = data_get($node->metadata, 'sentinel_host_image') ?? data_get($node->operations()
            ->where('command_type', UpgradeSentinel::COMMAND_TYPE)
            ->where('status', NodeOperationStatus::SUCCEEDED)
            ->latest('id')
            ->first()?->request, 'image');

        return is_string($image) && $image !== '' ? $image : config('constants.sentinel.host_image');
    }

    /** Whether a build, deploy, move, or lifecycle action runs on the Node, which the cleanup waits for. */
    public static function nodeIsBusy(Node $node): bool
    {
        return NodeBuild::query()->where('node_id', $node->id)->active()->whereNotNull('started_at')->exists()
            || $node->operations()->whereIn('command_type', self::BUSY_COMMANDS)->whereIn('status', self::ACTIVE_STATUSES)->exists();
    }

    /**
     * Runs a queued cleanup to a final state. Returns null while the Node is busy: the job tries again.
     */
    public function handle(NodeOperation $operation): ?NodeOperation
    {
        if ($operation->command_type !== self::COMMAND_TYPE || $operation->status->isFinal()) {
            return $operation;
        }
        $node = $operation->node;
        if ($operation->status === NodeOperationStatus::QUEUED && self::nodeIsBusy($node)) {
            // The stale-operation recovery closes an operation that does not change for 20 minutes.
            $operation->touch();

            return null;
        }
        // Dispatched until Sentinel returns: the command result is the final state.
        $claimed = ClaimOperation::run($operation, NodeOperationStatus::DISPATCHED);
        if ($claimed === null) {
            return $operation->refresh();
        }
        $operation = $claimed;

        try {
            $runningBuilds = NodeBuild::query()->where('node_id', $node->id)->active()->pluck('uuid')
                ->map(fn (string $uuid): string => 'coolify-build-'.$uuid)->values()->all();
            $keep = self::keepList($node);
            $data = SendFluxCommand::run('image.prune', [
                'server_id' => $node->uuid,
                'command_id' => $operation->uuid,
                'keep' => $keep,
                'running_build_containers' => $runningBuilds,
            ], timeout: 630);
            $validator = Validator::make($data, [
                'command_id' => ['required', 'string', 'in:'.$operation->uuid],
                'freed_bytes' => ['required', 'integer', 'min:0'],
                'removed_images' => ['present', 'array'],
                'removed_images.*' => ['string', 'max:1024'],
                'removed_containers' => ['present', 'array'],
                'removed_containers.*' => ['string', 'max:255'],
                'kept_images' => ['required', 'integer', 'min:0'],
                'skipped' => ['present', 'array'],
                'skipped.*' => ['string', 'max:2048'],
                'disk_available_bytes' => ['nullable', 'integer', 'min:0'],
            ]);
            if ($validator->fails()) {
                throw new RuntimeException('Flux returned an invalid image cleanup result.');
            }
            $result = $validator->validated();
            if (isset($result['disk_available_bytes'])) {
                $node->mergeMetadata(['disk_available_bytes' => $result['disk_available_bytes']]);
            }

            return TransitionOperation::run($operation, NodeOperationStatus::SUCCEEDED, result: [
                'trigger' => data_get($operation->request, 'trigger'),
                'freed_bytes' => $result['freed_bytes'],
                'removed_images' => array_slice($result['removed_images'], 0, 200),
                'removed_images_count' => count($result['removed_images']),
                'removed_containers' => array_slice($result['removed_containers'], 0, 200),
                'kept_images' => $result['kept_images'],
                'keep_list_count' => count($keep),
                'skipped' => array_slice($result['skipped'], 0, 50),
                'disk_available_bytes' => $result['disk_available_bytes'] ?? null,
            ]);
        } catch (Throwable $exception) {
            return TransitionOperation::run($operation, NodeOperationStatus::FAILED, error: mb_substr($exception->getMessage(), 0, 2000));
        }
    }
}
