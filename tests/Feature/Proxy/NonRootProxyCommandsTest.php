<?php

use App\Actions\Proxy\SaveProxyConfiguration;
use App\Actions\Proxy\StartProxy;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Jobs\RestartProxyJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/**
 * On the Coolify host, install.sh makes /data/coolify and /data/coolify/proxy `9999:root 0700`, so a
 * non-root SSH user cannot enter them. Proxy commands must reach proxy files only through sudo (#4255).
 */
beforeEach(function () {
    Server::flushIdentityMap();
    Event::fake([ProxyStatusChanged::class, ProxyStatusChangedUI::class]);
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    Process::fake();

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'user' => 'cooluser',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
});

/**
 * @return array<int, string>
 */
function nonRootProxyScriptLines(): array
{
    $lines = [];
    Process::assertRan(function ($process) use (&$lines) {
        array_push($lines, ...explode("\n", $process->command));

        return true;
    });

    return $lines;
}

function expectNoUnprivilegedProxyFileAccess(array $lines): void
{
    foreach ($lines as $line) {
        expect(trim($line))->not->toStartWith('cd ');
        // A redirect or a glob outside `sudo bash -c` runs as the SSH user.
        expect($line)->not->toMatch('/<\s*\S*\/data\/coolify|>\s*\/data\/coolify/');
        if (! str_starts_with(trim($line), 'sudo bash -c')) {
            expect($line)->not->toMatch('/\/data\/coolify\S*\*/');
        }
    }
}

function useNonRootProxy(Server $server, string $proxyType): void
{
    $server->proxy->type = $proxyType;
    $server->proxy->status = 'exited';
    $server->save();
    $server->refresh();
}

it('starts the proxy without entering proxy directories as the SSH user', function (string $proxyType, string $proxyPath) {
    useNonRootProxy($this->server, $proxyType);

    StartProxy::run($this->server, async: false, force: true);

    $lines = nonRootProxyScriptLines();
    expectNoUnprivilegedProxyFileAccess($lines);
    expect($lines)
        ->toContain("sudo docker compose -f {$proxyPath}/docker-compose.yml pull")
        ->toContain("sudo docker compose -f {$proxyPath}/docker-compose.yml up -d --wait --remove-orphans");
})->with([
    'traefik' => [ProxyTypes::TRAEFIK->value, '/data/coolify/proxy'],
    'caddy' => [ProxyTypes::CADDY->value, '/data/coolify/proxy/caddy'],
]);

it('writes the Caddyfile through sudo', function () {
    useNonRootProxy($this->server, ProxyTypes::CADDY->value);

    StartProxy::run($this->server, async: false, force: true);

    expect(nonRootProxyScriptLines())
        ->toContain("echo 'import /dynamic/*.caddy' | sudo tee /data/coolify/proxy/caddy/dynamic/Caddyfile > /dev/null");
});

it('deploys the swarm proxy stack with an absolute compose file path', function () {
    useNonRootProxy($this->server, ProxyTypes::TRAEFIK->value);
    $this->server->settings->update(['is_swarm_manager' => true]);
    $this->server->refresh();

    StartProxy::run($this->server, async: false, force: true);

    $lines = nonRootProxyScriptLines();
    expectNoUnprivilegedProxyFileAccess($lines);
    expect($lines)->toContain('sudo docker stack deploy --detach=true -c /data/coolify/proxy/docker-compose.yml coolify-proxy');
});

it('builds restart commands without entering proxy directories as the SSH user', function (string $proxyType, string $proxyPath) {
    useNonRootProxy($this->server, $proxyType);
    $configuration = $this->server->proxyType() === ProxyTypes::CADDY->value
        ? "services:\n  caddy:\n    image: lucaslorentz/caddy-docker-proxy:2.13-alpine\n"
        : "services:\n  traefik:\n    image: traefik:v3.6\n";

    $method = new ReflectionMethod(RestartProxyJob::class, 'buildRestartCommands');
    $commands = $method->invoke(new RestartProxyJob($this->server), $configuration);
    $lines = parseCommandsByLineForSudo(collect($commands), $this->server);

    expectNoUnprivilegedProxyFileAccess($lines);
    expect($lines)
        ->toContain("sudo docker compose -f {$proxyPath}/docker-compose.yml pull")
        ->toContain("sudo docker compose -f {$proxyPath}/docker-compose.yml up -d --wait --remove-orphans");
})->with([
    'traefik' => [ProxyTypes::TRAEFIK->value, '/data/coolify/proxy'],
    'caddy' => [ProxyTypes::CADDY->value, '/data/coolify/proxy/caddy'],
]);

it('backs up and prunes proxy configurations through sudo without bash', function () {
    useNonRootProxy($this->server, ProxyTypes::CADDY->value);
    $this->server->proxy->last_saved_settings = 'a1b2c3d4e5f6';
    $this->server->save();

    SaveProxyConfiguration::run($this->server, "services:\n  caddy:\n    image: lucaslorentz/caddy-docker-proxy:2.13-alpine\n");

    $lines = nonRootProxyScriptLines();
    $backups = '/data/coolify/proxy/caddy/backups';
    expectNoUnprivilegedProxyFileAccess($lines);
    // Alpine servers can run without bash, so the parser must not need `sudo bash -c` here.
    expect(collect($lines)->filter(fn (string $line) => str_contains($line, 'bash -c')))->toBeEmpty()
        ->and($lines)
        ->toContain("if sudo [ -z \"$(sudo find {$backups} -maxdepth 1 -name 'docker-compose.*.a1b2c3d4.yml')\" ]; then")
        ->toContain("sudo find {$backups} -maxdepth 1 -name 'docker-compose.*.yml' | sudo sort -r | sudo tail -n +11 | sudo xargs -r rm -f")
        ->and(collect($lines)->first(fn (string $line) => str_contains($line, "cp -f /data/coolify/proxy/caddy/docker-compose.yml {$backups}/")))
        ->toStartWith('sudo ');
});

it('runs the same proxy commands without sudo for a root SSH user', function (string $proxyType, string $proxyPath) {
    $this->server->update(['user' => 'root']);
    useNonRootProxy($this->server, $proxyType);
    $this->server->proxy->last_saved_settings = 'a1b2c3d4e5f6';
    $this->server->save();

    StartProxy::run($this->server, async: false, force: true);

    $lines = nonRootProxyScriptLines();
    expect(collect($lines)->filter(fn (string $line) => str_contains($line, 'sudo')))->toBeEmpty()
        ->and(collect($lines)->filter(fn (string $line) => str_starts_with(trim($line), 'cd ')))->toBeEmpty()
        ->and($lines)
        ->toContain("docker compose -f {$proxyPath}/docker-compose.yml pull")
        ->toContain("docker compose -f {$proxyPath}/docker-compose.yml up -d --wait --remove-orphans")
        ->and(collect($lines)->filter(fn (string $line) => str_starts_with($line, 'find ') && str_contains($line, '/backups -maxdepth 1')))->not->toBeEmpty();
})->with([
    'traefik' => [ProxyTypes::TRAEFIK->value, '/data/coolify/proxy'],
    'caddy' => [ProxyTypes::CADDY->value, '/data/coolify/proxy/caddy'],
]);
