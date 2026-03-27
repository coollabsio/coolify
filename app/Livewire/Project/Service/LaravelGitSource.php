<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class LaravelGitSource extends Component
{
    use AuthorizesRequests;

    public Service $service;

    public array $parameters;

    public string $repositoryUrl = '';

    public string $gitBranch = 'main';

    /** @var array<int, string> */
    public array $branches = [];

    public function mount(): void
    {
        $this->parameters = get_route_parameters();
        $this->service = Service::whereUuid(request()->route('service_uuid'))->firstOrFail();
        $this->authorize('view', $this->service);

        if (! $this->isLaravelRootkitStack()) {
            abort(404);
        }

        $this->repositoryUrl = (string) optional($this->service->environment_variables()->where('key', 'SERVICE_GITHUB_REPO_URL')->first())->value;
        $this->gitBranch = (string) (optional($this->service->environment_variables()->where('key', 'SERVICE_GITHUB_BRANCH')->first())->value ?? 'main');

        $this->loadBranches();
    }

    public function isLaravelRootkitStack(): bool
    {
        $raw = (string) ($this->service->docker_compose_raw ?? '');

        return str_contains($raw, 'APP_NAME=Laravel RootKit')
            || (str_contains($raw, 'SERVICE_GITHUB_REPO_URL')
                && str_contains($raw, 'SERVICE_DATABASE_LARAVEL')
                && str_contains($raw, 'phpmyadmin'));
    }

    public function saveGitSource(): void
    {
        $this->authorize('update', $this->service);

        $this->validate([
            'repositoryUrl' => 'required|url|max:500',
            'gitBranch' => 'required|string|max:120',
        ]);

        $fields = collect([
            [
                'key' => 'SERVICE_GITHUB_REPO_URL',
                'value' => trim($this->repositoryUrl),
            ],
            [
                'key' => 'SERVICE_GITHUB_BRANCH',
                'value' => trim($this->gitBranch),
            ],
        ]);

        $this->service->saveExtraFields($fields);
        $this->service->refresh();
        $this->dispatch('refreshEnvs');
        $this->dispatch('configurationChanged');
        $this->dispatch('success', 'Git source saved.');
    }

    public function updatedRepositoryUrl(): void
    {
        $this->loadBranches();
    }

    public function loadBranches(): void
    {
        $this->branches = [];
        $ownerRepo = $this->extractGithubOwnerRepo($this->repositoryUrl);
        if (! $ownerRepo) {
            return;
        }

        [$owner, $repo] = $ownerRepo;
        $response = Http::timeout(20)
            ->retry(2, 250, throw: false)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'Coolify-Laravel-RootKit',
            ])
            ->get("https://api.github.com/repos/{$owner}/{$repo}/branches", [
                'per_page' => 100,
            ]);

        if (! $response->successful()) {
            return;
        }

        $this->branches = collect($response->json())
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->unique()
            ->values()
            ->all();

        if ($this->branches !== [] && ! in_array($this->gitBranch, $this->branches, true)) {
            $this->gitBranch = $this->branches[0];
        }
    }

    private function extractGithubOwnerRepo(string $repoUrl): ?array
    {
        $url = trim($repoUrl);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'git@github.com:')) {
            $path = substr($url, strlen('git@github.com:'));
        } else {
            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                $url = 'https://'.$url;
            }
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if ($host !== 'github.com' && $host !== 'www.github.com') {
                return null;
            }
            $path = trim((string) ($parts['path'] ?? ''), '/');
        }

        $path = preg_replace('/\.git$/', '', $path) ?? $path;
        $segments = array_values(array_filter(explode('/', $path)));
        if (count($segments) < 2) {
            return null;
        }

        return [(string) $segments[0], (string) $segments[1]];
    }

    public function render()
    {
        return view('livewire.project.service.laravel-git-source');
    }
}

