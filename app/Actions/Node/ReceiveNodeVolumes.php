<?php

namespace App\Actions\Node;

use App\Models\Node;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Starts or polls one copy pass on the target Node of a stateful move. The first call starts the
 * pass in the background; the same call again returns its state. After `failed`, the next call
 * starts the pass again, and rsync sends only what is missing.
 */
class ReceiveNodeVolumes
{
    use AsAction;

    /**
     * @param  'warm'|'final'  $pass
     * @param  list<string>  $names
     * @return array{state: 'running'|'succeeded'|'failed', bytes_total: int, bytes_done: int, error: string}
     */
    public function handle(Node $target, string $transferId, string $pass, string $sourceAddress, int $sourcePort, string $secret, array $names): array
    {
        $target->ensureReportedCapability('volume.receive.v1');
        $commandId = (string) Str::uuid();
        $data = SendFluxCommand::run('volume.receive', [
            'server_id' => $target->uuid,
            'command_id' => $commandId,
            'transfer_id' => $transferId,
            'pass' => $pass,
            'source_address' => $sourceAddress,
            'source_port' => $sourcePort,
            'secret' => $secret,
            'names' => array_values($names),
        ]);

        $validator = Validator::make($data, [
            'command_id' => ['required', 'string', 'in:'.$commandId],
            'observed_at_unix_ms' => ['required', 'integer', 'min:1'],
            'state' => ['required', 'in:running,succeeded,failed'],
            'bytes_total' => ['required', 'integer', 'min:0'],
            'bytes_done' => ['required', 'integer', 'min:0'],
            'error' => ['nullable', 'string', 'max:4000'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('Flux returned an invalid volume copy state.');
        }
        $validated = $validator->validated();

        return [
            'state' => $validated['state'],
            'bytes_total' => (int) $validated['bytes_total'],
            'bytes_done' => (int) $validated['bytes_done'],
            'error' => (string) ($validated['error'] ?? ''),
        ];
    }
}
