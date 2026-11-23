<?php

namespace App\Actions\Application;

use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Checks that a GitHub, GitLab, or Gitea app can read a repository, and returns the repository ID that webhooks match on.
 */
class LookupGitAppRepository
{
    /**
     * @return array{source: GithubApp, repository: string, repository_project_id: mixed}
     *
     * @throws RuntimeException with code 400, 404, or 422
     */
    public function github(GithubApp $githubApp, string $gitRepository): array
    {
        $token = generateGithubInstallationToken($githubApp);
        if (! $token) {
            throw new RuntimeException('Failed to generate Github App token.', 400);
        }

        $gitRepository = gitRepositorySlug($gitRepository);
        $response = Http::GitHub($githubApp->api_url, $token)
            ->timeout(20)
            ->retry(3, 200, throw: false)
            ->get("/repos/{$gitRepository}");

        if ($response->status() === 404 || $response->status() === 403) {
            throw new RuntimeException('Repository not found or not accessible by the GitHub App.', 404);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Failed to verify repository access: '.($response->json()['message'] ?? 'Unknown error'), 400);
        }

        return ['source' => $githubApp, 'repository' => $gitRepository, 'repository_project_id' => data_get($response->json(), 'id')];
    }

    /**
     * @return array{source: GiteaApp, repository: string, repository_project_id: mixed}
     *
     * @throws RuntimeException with code 400, 404, or 422
     */
    public function gitea(?GiteaApp $giteaApp, string $gitRepository): array
    {
        if (! $giteaApp?->isConnected()) {
            throw new RuntimeException('Gitea App not found.', 404);
        }

        $repositoryPath = $this->giteaRepositoryPath($giteaApp, $gitRepository);
        if ($repositoryPath === null) {
            throw new RuntimeException('The git_repository must be owner/name or a repository URL on the Gitea instance of the source.', 422);
        }

        refreshGiteaToken($giteaApp);

        $response = Http::Gitea($giteaApp->apiUrlBase(), $giteaApp->access_token)
            ->timeout(20)
            ->retry(3, 200, throw: false)
            ->get('/repos/'.collect(explode('/', $repositoryPath))->map(fn (string $part) => rawurlencode($part))->implode('/'));

        if ($response->status() === 404 || $response->status() === 403
            || ($response->successful() && ! $giteaApp->allowsRepositoryOwner(data_get($response->json(), 'owner.login')))) {
            throw new RuntimeException('Repository not found or not accessible by the Gitea App.', 404);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Failed to verify repository access: '.data_get($response->json(), 'message', 'Unknown error'), 400);
        }

        return ['source' => $giteaApp, 'repository' => $repositoryPath, 'repository_project_id' => data_get($response->json(), 'id')];
    }

    /**
     * @return array{source: GitlabApp, repository: string, repository_project_id: mixed}
     *
     * @throws RuntimeException with code 400, 404, or 422
     */
    public function gitlab(?GitlabApp $gitlabApp, string $gitRepository): array
    {
        if (! $gitlabApp?->isConnected()) {
            throw new RuntimeException('GitLab App not found or not connected.', 404);
        }

        $repositoryPath = $this->repositoryPath((string) $gitlabApp->html_url, $gitRepository, nested: true);
        if ($repositoryPath === null) {
            throw new RuntimeException('The git_repository must be group/name or a repository URL on the GitLab instance of the source.', 422);
        }

        refreshGitlabToken($gitlabApp);

        $response = Http::GitLab($gitlabApp->apiUrlBase(), $gitlabApp->access_token)
            ->timeout(20)
            ->retry(3, 200, throw: false)
            ->get('/projects/'.rawurlencode($repositoryPath));

        if ($response->status() === 404 || $response->status() === 403) {
            throw new RuntimeException('Repository not found or not accessible by the GitLab App.', 404);
        }
        if (! $response->successful()) {
            throw new RuntimeException('Failed to verify repository access: '.data_get($response->json(), 'message', 'Unknown error'), 400);
        }

        return [
            'source' => $gitlabApp,
            'repository' => (string) data_get($response->json(), 'path_with_namespace', $repositoryPath),
            'repository_project_id' => data_get($response->json(), 'id'),
        ];
    }

    /**
     * Get owner/name from owner/name, or from an HTTPS or SSH URL on the Gitea instance of the source.
     */
    public function giteaRepositoryPath(GiteaApp $giteaApp, string $gitRepository): ?string
    {
        return $this->repositoryPath((string) $giteaApp->html_url, $gitRepository, nested: false);
    }

    /**
     * The repository path on the git host of htmlUrl. GitLab paths can have subgroups (group/subgroup/name).
     */
    private function repositoryPath(string $htmlUrl, string $gitRepository, bool $nested): ?string
    {
        $repository = sshGitUrlToHttps($gitRepository) ?? $gitRepository;
        if (str_contains($repository, '://')) {
            if (strtolower((string) parse_url($repository, PHP_URL_HOST)) !== strtolower((string) parse_url($htmlUrl, PHP_URL_HOST))) {
                return null;
            }
            $repository = Str::chopStart((string) parse_url($repository, PHP_URL_PATH), rtrim((string) parse_url($htmlUrl, PHP_URL_PATH), '/'));
        }
        $repository = str($repository)->trim('/')->replaceEnd('.git', '')->toString();

        $pattern = $nested ? '#^[\w.-]+(/[\w.-]+)+$#' : '#^[\w.-]+/[\w.-]+$#';
        if (! preg_match($pattern, $repository) || array_intersect(explode('/', $repository), ['.', '..']) !== []) {
            return null;
        }

        return $repository;
    }
}
