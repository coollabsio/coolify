<?php

namespace App\Livewire\Server\DockerRegistries;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Services\DockerRegistryLogins;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

#[Lazy]
class ServerRegistries extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public Server $server;

    /** @var array<int, array{registry: string, logged_in: bool, source: ?string, username: ?string, used_by: array<int, array{type: string, name: string, link: ?string}>}> */
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

        $usedBy = $this->imageUsers()->groupBy('registry');

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
                    ->map(fn (array $user) => Arr::except($user, 'registry'))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Every resource on this server that pulls or pushes a named image, with the registry of that image.
     * A service is listed once per registry, even if several of its containers use it.
     *
     * @return Collection<int, array{registry: string, type: string, name: string, link: ?string}>
     */
    private function imageUsers(): Collection
    {
        $user = fn (string $image, string $type, string $name, ?string $link) => [
            'registry' => DockerRegistryLogins::registryFromImage($image),
            'type' => $type,
            'name' => $name,
            'link' => $link,
        ];

        $applications = $this->server->applications()
            ->filter(fn (Application $application) => filled($application->docker_registry_image_name))
            ->map(fn (Application $application) => $user($application->docker_registry_image_name, 'Application', $application->name, $application->link()));

        $databases = $this->server->databases()
            ->filter(fn ($database) => filled($database->image))
            ->map(fn ($database) => $user($database->image, 'Database', $database->name, $database->link()));

        $services = $this->server->services()->with(['applications', 'databases'])->get()
            ->flatMap(fn (Service $service) => $service->applications->concat($service->databases)
                ->filter(fn ($container) => filled($container->image))
                ->map(fn ($container) => $user($container->image, 'Service', $service->name, $service->link())))
            ->unique(fn (array $entry) => $entry['registry'].'|'.$entry['link'].'|'.$entry['name']);

        return $applications->concat($databases)->concat($services)->values();
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
