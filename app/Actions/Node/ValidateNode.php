<?php

namespace App\Actions\Node;

use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;

class ValidateNode
{
    use AsAction;

    public const PODMAN_VALIDATION_COMMAND = 'command -v podman >/dev/null && command -v systemctl >/dev/null && systemctl is-active --quiet podman.socket && test -S /run/podman/podman.sock && podman info --format json';

    public function handle(Node $node): bool
    {
        $output = instant_remote_process([self::PODMAN_VALIDATION_COMMAND], $node, false, no_sudo: true);
        $isUsable = is_string($output) && json_decode(trim($output), true) !== null;

        $node->update([
            'is_reachable' => $output !== null,
            'is_usable' => $isUsable,
            'validation_logs' => $isUsable ? null : 'Podman or its API socket is not available.',
        ]);

        return $isUsable;
    }
}
