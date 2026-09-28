<?php

namespace App\Livewire\Server\DockerRegistries;

use App\Models\Application;
use App\Models\Server;
use App\Services\DockerRegistryLogins;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

#[Lazy]
class ServerRegistries extends Component
{
    use AuthorizesRequests;

    public Server $server;

    /** @var array<int, array{registry: string, logged_in: bool, source: ?string, username: ?string, used_by: array<int, array{name: string, link: ?string}>}> */
    public array $registries = [];

    public ?string $error = null;

    public function mount(Server $server): void
    {
        $this->authorize('update', $server);
        $this->server = $server;
        $this->loadRegistries();
    }

    public function loadRegistries(): void
    {
        $this->authorize('update', $this->server);
        $this->error = null;

        $loggedIn = [];
        if (! $this->server->isFunctional()) {
            $this->error = 'The server is not reachable. Validate the server to read its registry logins.';
        } else {
            try {
                $loggedIn = DockerRegistryLogins::forServer($this->server);
            } catch (Throwable) {
                $this->error = 'Could not read the Docker configuration on this server.';
            }
        }

        $usedBy = $this->server->applications()
            ->filter(fn (Application $application) => filled($application->docker_registry_image_name))
            ->groupBy(fn (Application $application) => DockerRegistryLogins::registryFromImage($application->docker_registry_image_name));

        $this->registries = collect(array_keys($loggedIn))
            ->merge($usedBy->keys())
            ->unique()
            ->sort()
            ->map(fn (string $registry) => [
                'registry' => $registry,
                'logged_in' => isset($loggedIn[$registry]),
                'source' => $loggedIn[$registry]['source'] ?? null,
                'username' => $loggedIn[$registry]['username'] ?? null,
                'used_by' => ($usedBy[$registry] ?? collect())
                    ->map(fn (Application $application) => ['name' => $application->name, 'link' => $application->link()])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    #[On('registryLoginsChanged')]
    public function refreshAfterLogin(int $serverId): void
    {
        if ($serverId === $this->server->id) {
            $this->loadRegistries();
        }
    }

    public function logout(string $registry): void
    {
        $this->authorize('update', $this->server);
        $registry = DockerRegistryLogins::normalizeRegistry($registry);
        if (! preg_match(DockerRegistryLogins::REGISTRY_PATTERN, $registry)) {
            $this->dispatch('error', 'Logout failed.', 'The registry name is not valid.');

            return;
        }

        try {
            DockerRegistryLogins::logout($this->server, $registry);
        } catch (Throwable $exception) {
            $this->dispatch('error', 'Logout failed.', e($exception->getMessage()));

            return;
        }

        DockerRegistryLogins::audit($this->server, 'logout', $registry);
        $this->dispatch('success', "Logged out from {$registry}.");
        $this->loadRegistries();
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="rounded-xl border border-neutral-200 bg-white p-4 text-[12px] text-neutral-500 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
            Loading registries...
        </div>
        HTML;
    }

    public function render()
    {
        return view('livewire.server.docker-registries.server-registries');
    }
}
