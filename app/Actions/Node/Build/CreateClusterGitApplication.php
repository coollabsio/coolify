<?php

namespace App\Actions\Node\Build;

use App\Enums\NodeWorkloadSourceType;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * The create step of a cluster application or stack from Git, for the Git pages and the API
 * (decision 0029). It reads the branch with the credential first, so a missing access fails here
 * and not at the first build. The Compose build pack creates a stack. Like v4 applications,
 * creating does not deploy.
 */
class CreateClusterGitApplication
{
    use AsAction;

    /**
     * @param  array{name?: ?string, base_directory?: ?string, dockerfile_location?: ?string, publish_directory?: ?string, compose_location?: ?string, spread?: bool}  $options
     */
    public function handle(
        Project $project,
        Environment $environment,
        NodeCluster $cluster,
        ?Node $targetNode,
        string $buildPack,
        string $repository,
        string $branch,
        User $user,
        ?GithubApp $githubApp = null,
        ?PrivateKey $privateKey = null,
        array $options = [],
    ): NodeWorkload|NodeStack {
        Gate::forUser($user)->authorize('create', [NodeWorkload::class, $environment]);
        if ($githubApp !== null || $privateKey !== null) {
            Gate::forUser($user)->authorize('useTeamCredentials', new NodeWorkload(['team_id' => $project->team_id]));
        }

        if ($buildPack === NodeWorkloadSourceType::COMPOSE_BUILD_PACK) {
            Gate::forUser($user)->authorize('create', [NodeStack::class, $environment]);

            return CreateClusterGitStack::run(
                $project,
                $environment,
                $cluster,
                $repository,
                $branch,
                $user,
                $targetNode,
                $options['name'] ?? null,
                $options['base_directory'] ?? null,
                $options['compose_location'] ?? null,
                $githubApp,
                $privateKey,
                $targetNode === null && ($options['spread'] ?? false),
            );
        }

        $unsaved = new NodeWorkload(['team_id' => $project->team_id, 'github_app_id' => $githubApp?->id, 'private_key_id' => $privateKey?->id]);
        ResolveGitCommit::run($repository, $branch, IssueNodeGitCredential::run($unsaved));

        return CreateClusterGitWorkload::run(
            $project,
            $environment,
            $cluster,
            NodeWorkloadSourceType::from($buildPack),
            $repository,
            $branch,
            $user,
            $targetNode,
            $options['name'] ?? null,
            $options['base_directory'] ?? null,
            $options['dockerfile_location'] ?? null,
            $githubApp,
            $privateKey,
            $options['publish_directory'] ?? null,
        );
    }
}
