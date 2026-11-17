<?php

namespace App\Actions\Node;

use App\Models\Node;
use App\Models\NodeVolume;
use App\Models\NodeVolumeCopy;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Deletes a Node record, for the Node page and the API (decision 0029). The Node must be in no
 * cluster and run no applications. Coolify runs no command on the server: Sentinel and the data
 * stay on it. Only a team admin may delete a Node (`NodePolicy::delete`).
 *
 * A Node that still holds volumes or volume copies is deleted only with `force`, for a server that
 * is gone for good: after the delete, Coolify cannot remove that data from the server.
 */
class DeleteNode
{
    use AsAction;

    public const IN_USE_ERROR = 'Remove this server from its cluster and move or delete its applications before deleting it.';

    /**
     * @param  string  $source  `ui` or `api`, for the audit log.
     *
     * @throws DomainException When the Node is in a cluster or runs applications, or holds volume data without `force`.
     */
    public function handle(Node $node, User $user, bool $force = false, string $source = 'ui'): void
    {
        Gate::forUser($user)->authorize('delete', $node);
        $deleted = DB::transaction(function () use ($node, $force, $source): bool {
            $node = Node::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            if ($node->node_cluster_id !== null || $node->workloads()->exists()) {
                return false;
            }
            ['volumes' => $volumes, 'copies' => $copies] = self::dataOn($node);
            if ($volumes + $copies > 0) {
                if (! $force) {
                    throw new DomainException(self::dataError($volumes, $copies));
                }
                auditLog("{$source}.node.force_deleted", [
                    'team_id' => $node->team_id,
                    'node_uuid' => $node->uuid,
                    'node_name' => $node->name,
                    'volumes' => $volumes,
                    'volume_copies' => $copies,
                    'note' => 'The volume data stays on the server. Coolify cannot remove it after the delete.',
                ], 'warning');
            }

            return (bool) $node->delete();
        });
        if (! $deleted) {
            throw new DomainException(self::IN_USE_ERROR);
        }
    }

    /**
     * The volume data that Coolify knows on the Node: volumes placed on it and copies of volumes.
     *
     * @return array{volumes: int, copies: int}
     */
    public static function dataOn(Node $node): array
    {
        return [
            'volumes' => NodeVolume::query()->where('node_id', $node->id)->count(),
            'copies' => NodeVolumeCopy::query()->where('node_id', $node->id)->count(),
        ];
    }

    public static function dataError(int $volumes, int $copies): string
    {
        return "This server still holds {$volumes} ".str('volume')->plural($volumes)." and {$copies} volume ".str('copy')->plural($copies).'. '
            .'Move or delete them first. If the server is gone for good, use the forced delete: the data then stays on the server, and Coolify cannot remove it.';
    }
}
