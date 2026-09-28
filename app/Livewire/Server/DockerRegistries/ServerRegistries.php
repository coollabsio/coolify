<?php

namespace App\Livewire\Server\DockerRegistries;

use App\Models\Server;
use App\Services\DockerRegistryLogins;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
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

    /** @var array<string, array{ok: bool, message: string}> result of the last login check, per registry */
    public array $loginChecks = [];

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
        $this->loginChecks = [];

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

        $this->registries = DockerRegistryLogins::rows($this->server, $loggedIn);
    }

    #[On('registryLoginsChanged')]
    public function refreshAfterLogin(int $serverId): void
    {
        if ($serverId === $this->server->id) {
            $this->loadRegistries();
        }
    }

    public function testLogin(string $registry): void
    {
        $this->authorize('update', $this->server);
        $registry = DockerRegistryLogins::normalizeRegistry($registry);
        $isLoggedIn = collect($this->registries)->contains(fn (array $row) => $row['registry'] === $registry && $row['logged_in']);
        if (! $isLoggedIn || ! preg_match(DockerRegistryLogins::REGISTRY_PATTERN, $registry)) {
            return;
        }

        try {
            DockerRegistryLogins::checkLogin($this->server, $registry);
            $this->loginChecks[$registry] = ['ok' => true, 'message' => 'The login works.'];
            $this->dispatch('success', "The login for {$registry} works.");
        } catch (Throwable $exception) {
            $this->loginChecks[$registry] = ['ok' => false, 'message' => $exception->getMessage()];
            $this->dispatch('error', "The login for {$registry} does not work.", e($exception->getMessage()));
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
        $this->dispatch('success', "Deleted the login for {$registry}.");
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
