<?php

namespace App\Actions\Node;

use App\Models\Node;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/** Deletes Podman volumes on a Node. Sentinel rejects a volume that a container uses; a missing volume is reported as missing. */
class DeleteNodeVolumes
{
    use AsAction;

    /**
     * @param  list<string>  $names
     * @return array{deleted: list<string>, missing: list<string>}
     */
    public function handle(Node $node, array $names): array
    {
        if ($names === []) {
            return ['deleted' => [], 'missing' => []];
        }
        $node->ensureReportedCapability('volume.delete.v1');
        $commandId = (string) Str::uuid();
        $data = SendFluxCommand::run('volume.delete', [
            'server_id' => $node->uuid,
            'command_id' => $commandId,
            'names' => array_values($names),
        ], timeout: 330);

        $validator = Validator::make($data, [
            'command_id' => ['required', 'string', 'in:'.$commandId],
            'observed_at_unix_ms' => ['required', 'integer', 'min:1'],
            'deleted' => ['present', 'array', 'max:1000'],
            'deleted.*' => ['string', 'max:255'],
            'missing' => ['present', 'array', 'max:1000'],
            'missing.*' => ['string', 'max:255'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid volume deletion result.');
        }

        $validated = $validator->validated();

        return ['deleted' => array_values($validated['deleted']), 'missing' => array_values($validated['missing'])];
    }

    /** Whether Sentinel rejected the deletion because a container uses the volume. */
    public static function isInUse(\Throwable $exception): bool
    {
        return $exception instanceof RuntimeException && str_contains($exception->getMessage(), 'is used by the container');
    }
}
