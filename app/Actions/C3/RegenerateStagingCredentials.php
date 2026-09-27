<?php

namespace App\Actions\C3;

use App\Models\Project;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Connect3 fork: rotate the staging basic-auth password. Only the proxy file changes, so the
 * new credentials are effective immediately without a redeploy.
 */
class RegenerateStagingCredentials
{
    use AsAction;

    public function handle(Project $project): void
    {
        $project->staging_auth_user = $project->staging_auth_user ?: $project->client_slug;
        $project->staging_auth_pass = c3_generatePassword();
        $project->save();

        auditLog('c3.staging_credentials.regenerated', [
            'project_uuid' => $project->uuid,
            'client_slug' => $project->client_slug,
        ]);
    }
}
