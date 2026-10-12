<?php

namespace App\Actions\Node;

use App\Enums\NodeOperationStatus;
use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class RotateSentinelToken
{
    use AsAction;

    /**
     * Issue a new Sentinel token and reinstall Sentinel with it, which revokes the previous token.
     * The previous token is restored if the host cannot receive the new one, so the node is not locked out.
     */
    public function handle(Node $node): void
    {
        $previousToken = $node->getAttributes()['sentinel_token'] ?? null;
        // Reinstall the image of the last successful upgrade, so the rotation does not change the Sentinel version.
        $image = data_get($node->operations()
            ->where('command_type', UpgradeSentinel::COMMAND_TYPE)
            ->where('status', NodeOperationStatus::SUCCEEDED)
            ->latest('id')
            ->first(), 'request.image');
        $node->forceFill(['sentinel_token' => $node->newSentinelToken()])->save();

        try {
            if (InstallSentinel::run($node, is_string($image) && $image !== '' ? $image : null) === null) {
                throw new RuntimeException('Sentinel could not be reinstalled with the new token.');
            }
        } catch (Throwable $exception) {
            $node->setRawAttributes(array_merge($node->getAttributes(), ['sentinel_token' => $previousToken]));
            $node->save();

            throw $exception;
        }
    }
}
