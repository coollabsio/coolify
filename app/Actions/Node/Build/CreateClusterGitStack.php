<?php

namespace App\Actions\Node\Build;

use App\Actions\Node\CreateClusterStack;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\User;
use App\Services\NodeStack\NodeStackGitSource;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Creates a stack from the Compose file of a Git repository (step 2 of decision 0023): it reads
 * the file at the head of the branch, with the GitHub App or deploy key of the project team, then
 * creates the stack with that source. Its services with `build:` build at the first deploy.
 */
class CreateClusterGitStack
{
    use AsAction;

    public function handle(
        Project $project,
        Environment $environment,
        NodeCluster $cluster,
        string $repository,
        string $branch,
        User $requestedBy,
        ?Node $targetNode = null,
        ?string $name = null,
        ?string $baseDirectory = null,
        ?string $composeLocation = null,
        ?GithubApp $githubApp = null,
        ?PrivateKey $privateKey = null,
    ): NodeStack {
        // Checks the source against the project team before the credential is issued.
        CreateClusterGitWorkload::ensureSource($project, $repository, $githubApp, $privateKey);
        $credential = IssueNodeGitCredential::run(new NodeWorkload(['team_id' => $project->team_id, 'github_app_id' => $githubApp?->id, 'private_key_id' => $privateKey?->id]));
        $composeLocation = filled($composeLocation) ? $composeLocation : NodeStackGitSource::DEFAULT_COMPOSE_LOCATION;
        $file = ReadGitComposeFile::run($repository, $branch, $credential, $baseDirectory, $composeLocation);

        return CreateClusterStack::run(
            $project,
            $environment,
            $cluster,
            $file['content'],
            $requestedBy,
            $targetNode,
            $name,
            git: new NodeStackGitSource($repository, $branch, (string) $baseDirectory, $composeLocation, $file['commit_sha'], $githubApp, $privateKey),
        );
    }
}
