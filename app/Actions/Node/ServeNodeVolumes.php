<?php

namespace App\Actions\Node;

use App\Models\Node;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Starts (or returns) the read-only rsync service of a stateful move on the source Node. It is
 * idempotent per transfer; a later expiry extends it. `stop()` ends it and removes its firewall rule.
 */
class ServeNodeVolumes
{
    use AsAction;

    /**
     * @param  list<string>  $names
     * @return array{address: string, port: int, volumes: list<array{name: string, used_bytes: int}>}
     */
    public function handle(Node $source, string $transferId, array $names, string $peerIp, string $secret, CarbonInterface $expiresAt): array
    {
        $source->ensureReportedCapability('volume.serve.v1');
        $commandId = (string) Str::uuid();
        $data = SendFluxCommand::run('volume.serve', [
            'server_id' => $source->uuid,
            'command_id' => $commandId,
            'transfer_id' => $transferId,
            'names' => array_values($names),
            'peer_ip' => $peerIp,
            'secret' => $secret,
            'expires_at_unix_ms' => $expiresAt->getTimestampMs(),
            'stop' => false,
        ]);

        $validator = Validator::make($data, [
            'command_id' => ['required', 'string', 'in:'.$commandId],
            'observed_at_unix_ms' => ['required', 'integer', 'min:1'],
            'address' => ['required', 'ip'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'volumes' => ['present', 'array', 'max:32'],
            'volumes.*.name' => ['required', 'string', 'in:'.implode(',', $names), 'distinct'],
            'volumes.*.used_bytes' => ['required', 'integer', 'min:0'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid volume transfer service.');
        }
        $validated = $validator->validated();

        return [
            'address' => $validated['address'],
            'port' => (int) $validated['port'],
            'volumes' => array_values($validated['volumes']),
        ];
    }

    public function stop(Node $source, string $transferId): void
    {
        $source->ensureReportedCapability('volume.serve.v1');
        SendFluxCommand::run('volume.serve', [
            'server_id' => $source->uuid,
            'command_id' => (string) Str::uuid(),
            'transfer_id' => $transferId,
            'stop' => true,
        ]);
    }
}
