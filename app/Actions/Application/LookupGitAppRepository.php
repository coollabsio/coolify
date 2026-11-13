<?php

namespace App\Actions\Application;

use App\Models\GiteaApp;
use App\Models\GithubApp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Checks that a git app can read a repository, and returns the repository ID that webhooks match on.
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
     * Get owner/name from owner/name, or from an HTTPS or SSH URL on the Gitea instance of the source.
     */
    public function giteaRepositoryPath(GiteaApp $giteaApp, string $gitRepository): ?string
    {
        $repository = sshGitUrlToHttps($gitRepository) ?? $gitRepository;
        if (str_contains($repository, '://')) {
            if (strtolower((string) parse_url($repository, PHP_URL_HOST)) !== strtolower((string) parse_url($giteaApp->html_url, PHP_URL_HOST))) {
                return null;
            }
            $repository = Str::chopStart((string) parse_url($repository, PHP_URL_PATH), rtrim((string) parse_url($giteaApp->html_url, PHP_URL_PATH), '/'));
        }
        $repository = str($repository)->trim('/')->replaceEnd('.git', '')->toString();

        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $repository) || array_intersect(explode('/', $repository), ['.', '..']) !== []) {
            return null;
        }

        return $repository;
    }
}
