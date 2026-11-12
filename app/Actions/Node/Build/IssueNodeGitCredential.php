<?php

namespace App\Actions\Node\Build;

use App\Models\GithubApp;
use App\Models\NodeRegistry;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * The Git credential of one build of a private repository, as the fields of
 * the `image.build` request: a new GitHub App installation token (valid for
 * one hour), or the deploy key. Empty for a public repository. It checks
 * again that the source belongs to the team of the workload. Do not store
 * the result. Builder Nodes need {@see NodeRegistry::PRIVATE_GIT_BUILD_CAPABILITY}.
 */
class IssueNodeGitCredential
{
    use AsAction;

    /** @return array{git_https_token?: string, git_ssh_private_key?: string} */
    public function handle(NodeWorkload $workload): array
    {
        if ($workload->github_app_id !== null && $workload->private_key_id !== null) {
            throw new RuntimeException('The application has both a GitHub App and a deploy key. Keep one.');
        }
        if ($workload->github_app_id !== null) {
            $githubApp = self::githubApps($workload->team_id)->find($workload->github_app_id)
                ?? throw new RuntimeException('The GitHub App of this application is not available to its team anymore.');

            return ['git_https_token' => $githubApp->installationToken()];
        }
        if ($workload->private_key_id !== null) {
            $privateKey = self::privateKeys($workload->team_id)->find($workload->private_key_id)
                ?? throw new RuntimeException('The deploy key of this application is not available to its team anymore.');

            return ['git_ssh_private_key' => $privateKey->private_key];
        }

        return [];
    }

    /**
     * The GitHub Apps that a team can build with: its own and the system-wide ones, like v4. The
     * public GitHub source and Apps without an installation cannot issue a token.
     *
     * @return Builder<GithubApp>
     */
    public static function githubApps(int $teamId): Builder
    {
        return GithubApp::query()
            ->where(fn (Builder $query) => $query->where('team_id', $teamId)->orWhere('is_system_wide', true))
            ->where('is_public', false)
            ->whereNotNull('app_id')
            ->whereNotNull('installation_id')
            ->whereNotNull('private_key_id');
    }

    /**
     * The deploy keys that a team can build with: its own private keys, like v4.
     *
     * @return Builder<PrivateKey>
     */
    public static function privateKeys(int $teamId): Builder
    {
        return PrivateKey::query()
            ->where('team_id', $teamId)
            ->when(! isDev(), fn (Builder $query) => $query->where('id', '!=', 0));
    }
}
