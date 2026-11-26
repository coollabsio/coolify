<?php

namespace App\Livewire\Server\DockerRegistries;

use App\Models\Server;
use App\Services\DockerRegistryLogins;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class Login extends Component
{
    use AuthorizesRequests;

    /**
     * A provider with a registry has one fixed host. A provider with a host pattern has one host per region or account,
     * so the user types it and it must match the pattern.
     *
     * @var array<string, array{label: string, registry: string, username: string, placeholder: string, hint: string, host_pattern?: string}>
     */
    public const PROVIDERS = [
        'dockerhub' => ['label' => 'Docker Hub', 'registry' => 'docker.io', 'username' => '', 'placeholder' => 'docker.io', 'hint' => 'Use your Docker Hub username and a personal access token.'],
        'ghcr' => ['label' => 'GitHub Container Registry', 'registry' => 'ghcr.io', 'username' => '', 'placeholder' => 'ghcr.io', 'hint' => 'Use your GitHub username and a personal access token (classic) with the read:packages scope.'],
        'gitlab' => ['label' => 'GitLab Container Registry', 'registry' => 'registry.gitlab.com', 'username' => '', 'placeholder' => 'registry.gitlab.com', 'hint' => 'Use a deploy token or a personal access token with the read_registry scope.'],
        'google' => ['label' => 'Google Artifact Registry', 'registry' => '', 'username' => '_json_key', 'placeholder' => 'europe-west1-docker.pkg.dev', 'hint' => 'Paste the JSON key of a service account with the Artifact Registry Reader role.', 'host_pattern' => '/(^|\.)gcr\.io$|-docker\.pkg\.dev$/'],
        'azure' => ['label' => 'Azure Container Registry', 'registry' => '', 'username' => '', 'placeholder' => 'myregistry.azurecr.io', 'hint' => 'Use a service principal ID and secret, or a repository-scoped token.', 'host_pattern' => '/\.azurecr\.(io|cn|us)$/'],
        'aws' => ['label' => 'AWS ECR', 'registry' => '', 'username' => 'AWS', 'placeholder' => '123456789012.dkr.ecr.eu-west-1.amazonaws.com', 'hint' => 'Paste the output of aws ecr get-login-password. This token expires after 12 hours.', 'host_pattern' => '/^[0-9]{12}\.dkr\.ecr(-fips)?\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/'],
        'custom' => ['label' => 'Other', 'registry' => '', 'username' => '', 'placeholder' => 'registry.example.com', 'hint' => ''],
    ];

    /** The server of a card. Null on the overview page, where the user picks the servers. */
    #[Locked]
    public ?Server $server = null;

    /** @var array<int, string> uuids of the servers to log in to, when there is no card server */
    public array $selectedServers = [];

    public string $provider = 'custom';

    /** Registry of an existing login being edited; editing keeps the registry fixed. */
    #[Locked]
    public ?string $editRegistry = null;

    public string $registry = '';

    public string $username = '';

    public string $password = '';

    /**
     * The server parameter has no type hint on purpose: with one, Livewire would inject an empty Server model on the overview page.
     *
     * @param  Server|null  $server
     */
    public function mount($server = null, ?string $editRegistry = null, ?string $currentUsername = null, ?string $presetRegistry = null): void
    {
        if ($server instanceof Server) {
            $this->authorize('update', $server);
            $this->server = $server;
        } else {
            abort_unless(auth()->user()?->isAdmin(), 403);
        }

        if ($editRegistry !== null) {
            $this->editRegistry = DockerRegistryLogins::normalizeRegistry($editRegistry);
            $this->provider = self::providerFor($this->editRegistry);
            $this->registry = $this->editRegistry;
            $this->username = $currentUsername ?? '';
        } elseif ($presetRegistry !== null) {
            $this->registry = DockerRegistryLogins::normalizeRegistry($presetRegistry);
            $this->provider = self::providerFor($this->registry);
            $this->username = $this->preset()['username'];
        }
    }

    /**
     * Servers of the current team that the user may log in to from the overview page.
     *
     * @return Collection<int, Server>
     */
    private function availableServers(): Collection
    {
        return Server::ownedByCurrentTeamCached()
            ->filter(fn (Server $server) => $server->isFunctional() && auth()->user()->can('update', $server))
            ->values();
    }

    /**
     * @return Collection<int, Server>
     */
    private function targetServers(): Collection
    {
        if ($this->server !== null) {
            $this->authorize('update', $this->server);

            return collect([$this->server]);
        }

        $this->validate(['selectedServers' => ['required', 'array', 'min:1']], ['selectedServers.required' => 'Choose at least one server.']);
        $servers = Server::ownedByCurrentTeam()->whereIn('uuid', $this->selectedServers)->get();
        abort_if($servers->count() !== count(array_unique($this->selectedServers)), 403);
        $servers->each(fn (Server $server) => $this->authorize('update', $server));

        return $servers;
    }

    public static function providerFor(string $registry): string
    {
        foreach (self::PROVIDERS as $provider => $preset) {
            if ($preset['registry'] === $registry || (isset($preset['host_pattern']) && preg_match($preset['host_pattern'], $registry))) {
                return $provider;
            }
        }

        return 'custom';
    }

    /**
     * @return array{label: string, registry: string, username: string, placeholder: string, hint: string, host_pattern?: string}
     */
    private function preset(): array
    {
        return self::PROVIDERS[$this->provider] ?? self::PROVIDERS['custom'];
    }

    public function updatedProvider(): void
    {
        $preset = $this->preset();
        $this->registry = $this->editRegistry ?? $preset['registry'];
        $this->username = $preset['username'];
        $this->password = '';
    }

    public function login(): void
    {
        $servers = $this->targetServers();
        $preset = $this->preset();
        $this->registry = $this->editRegistry
            ?? ($preset['registry'] !== '' ? $preset['registry'] : DockerRegistryLogins::normalizeRegistry($this->registry));
        $hostRules = $this->editRegistry === null && isset($preset['host_pattern']) ? ['regex:'.$preset['host_pattern']] : [];
        $this->validate([
            'registry' => ['required', 'string', 'max:255', 'regex:'.DockerRegistryLogins::REGISTRY_PATTERN, ...$hostRules],
            'username' => ['required', 'string', 'max:255', 'regex:/^[^\\s]+$/'],
            'password' => ['required', 'string', 'max:20000'],
        ], [
            'registry.regex' => $hostRules
                ? "Enter a valid {$preset['label']} host, for example {$preset['placeholder']}. Choose Other for a different registry."
                : 'Enter a registry host, for example ghcr.io or registry.example.com:5000.',
            'username.regex' => 'The username must not contain spaces.',
        ]);

        $failures = [];
        foreach ($servers as $server) {
            try {
                DockerRegistryLogins::login($server, $this->registry, $this->username, $this->password);
                DockerRegistryLogins::audit($server, $this->auditAction(), $this->registry);
                $this->dispatch('registryLoginsChanged', serverId: $server->id);
            } catch (Throwable $exception) {
                DockerRegistryLogins::audit($server, $this->auditAction(), $this->registry, succeeded: false);
                $failures[$server->name] = $exception->getMessage();
            }
        }
        $this->password = '';

        if ($failures !== []) {
            $details = collect($failures)->map(fn (string $error, string $name) => count($servers) > 1 ? e($name).': '.e($error) : e($error))->implode('<br>');
            $this->dispatch('error', count($failures) === count($servers) ? 'Login failed.' : 'Login failed on some servers.', $details);

            return;
        }

        $this->dispatch('success', $this->editRegistry ? "Updated the login for {$this->registry}." : "Logged in to {$this->registry}.");
        if ($this->editRegistry === null) {
            $this->reset('provider', 'registry', 'username', 'selectedServers');
        }
        $this->dispatch('close-modal');
    }

    private function auditAction(): string
    {
        return $this->editRegistry ? 'login_updated' : 'login';
    }

    public function render()
    {
        return view('livewire.server.docker-registries.login', [
            'serverOptions' => $this->server === null ? $this->availableServers() : collect(),
            'preset' => $this->preset(),
            'providerOptions' => collect(self::PROVIDERS)
                ->map(fn (array $preset, string $value) => ['value' => $value, 'label' => $preset['label']])
                ->values()
                ->all(),
        ]);
    }
}
