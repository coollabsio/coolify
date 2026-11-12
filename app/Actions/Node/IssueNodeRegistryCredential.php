<?php

namespace App\Actions\Node;

use App\Models\NodeWorkload;
use App\Models\RegistryCredential;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * The registry login of a workload for one image, read again at each deploy or build: the
 * credential must still belong to the team of the workload and be for the registry of the image.
 * Null when the workload has no credential. Do not store the result.
 */
class IssueNodeRegistryCredential
{
    use AsAction;

    /** Accepts `registry_username` and `registry_password` on `workload.deploy`. */
    public const DEPLOY_CAPABILITY = 'workload.registry_auth.v1';

    /** Accepts `push_image`, `push_username`, and `push_password` on `image.build`. */
    public const PUSH_CAPABILITY = 'image.build.external_push.v1';

    /** @return array{username: string, password: string}|null */
    public function handle(NodeWorkload $workload, string $image): ?array
    {
        if ($workload->registry_credential_id === null) {
            return null;
        }
        $credential = RegistryCredential::query()
            ->where('team_id', $workload->team_id)
            ->find($workload->registry_credential_id)
            ?? throw new RuntimeException('The registry credential of this application is not available to its team anymore. Select another one in the General settings.');
        if (! $credential->matchesImage($image)) {
            throw new RuntimeException("The registry credential {$credential->name} is for {$credential->registry}, not for the registry of {$image}.");
        }

        return ['username' => $credential->username, 'password' => $credential->password];
    }
}
