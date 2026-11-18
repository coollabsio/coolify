<?php

use App\Enums\ProxyTypes;
use App\Livewire\Server\Proxy\NewDynamicConfiguration;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'ip' => '203.0.113.21',
        'proxy' => ['type' => ProxyTypes::CADDY->value, 'status' => 'running'],
    ]);
    $this->server->settings->forceFill(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false])->save();

    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    $this->dynamicFile = $this->server->proxyPath().'/dynamic/site.caddy';
});

/**
 * @param  array{proxy_state?: string, reload_fails?: bool, read_fails?: bool, current_file?: string}  $options
 */
function fakeCaddyProxy(array $options = []): void
{
    Process::fake(function ($process) use ($options) {
        $script = remoteProcessScript($process);
        if (str_contains($script, 'docker inspect')) {
            return Process::result(output: json_encode(['State' => ['Status' => $options['proxy_state'] ?? 'running']]));
        }
        if (str_contains($script, 'caddy reload')) {
            return ($options['reload_fails'] ?? false)
                ? Process::result(errorOutput: 'Error: adapting config using caddyfile: unrecognized directive', exitCode: 1)
                : Process::result();
        }
        if (str_contains($script, 'base64 -w0')) {
            return ($options['read_fails'] ?? false)
                ? Process::result(errorOutput: 'Connection reset', exitCode: 255)
                : Process::result(output: base64_encode($options['current_file'] ?? ''));
        }

        return Process::result();
    });
}

function caddyFileWrites(string $file): array
{
    $writes = [];
    Process::assertRan(function ($process) use ($file, &$writes) {
        $script = remoteProcessScript($process);
        if (str_contains($script, "tee '{$file}'") && preg_match("/echo '([^']*)' \\| base64 -d/", $script, $matches)) {
            $writes[] = base64_decode($matches[1]);
        }

        return true;
    });

    return $writes;
}

it('removes a global options block that Caddy rejects and explains where global options go', function (string $value) {
    fakeCaddyProxy(['reload_fails' => true]);

    Livewire::test(NewDynamicConfiguration::class, ['server_id' => $this->server->id, 'newFile' => true])
        ->set('fileName', 'log')
        ->set('value', $value)
        ->call('addDynamicConfiguration')
        ->assertDispatched('error', fn (string $event, array $params) => str_contains($params[0], 'labels of the proxy container'))
        ->assertNotDispatched('success');

    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), "rm -f '{$this->server->proxyPath()}/dynamic/log.caddy'"));
})->with([
    'global block' => "{\n\tlog {\n\t\toutput stdout\n\t}\n}\n",
    'after comments' => "# global options\n\n{ \n\temail admin@example.com\n}\n",
    'one line' => "{\n}\n",
]);

it('saves a global options block that Caddy accepts, as in a custom base file', function () {
    fakeCaddyProxy();
    $value = "{\n\tlog\n}\nimport /dynamic/coolify.caddy";

    Livewire::test(NewDynamicConfiguration::class, ['server_id' => $this->server->id, 'newFile' => true])
        ->set('fileName', 'base')
        ->set('value', $value)
        ->call('addDynamicConfiguration')
        ->assertDispatched('success');

    expect(caddyFileWrites($this->server->proxyPath().'/dynamic/base.caddy'))->toBe([$value]);
});

it('saves a site block that uses an environment variable as its address', function () {
    fakeCaddyProxy();
    $value = "{\$DOMAIN} {\n\trespond 200\n}";

    Livewire::test(NewDynamicConfiguration::class, ['server_id' => $this->server->id, 'newFile' => true])
        ->set('fileName', 'site')
        ->set('value', $value)
        ->call('addDynamicConfiguration')
        ->assertDispatched('success')
        ->assertDispatched('close-modal');

    expect(caddyFileWrites($this->dynamicFile))->toBe([$value]);
    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), 'caddy reload'));
});

it('removes a new file that Caddy rejects', function () {
    fakeCaddyProxy(['reload_fails' => true]);

    Livewire::test(NewDynamicConfiguration::class, ['server_id' => $this->server->id, 'newFile' => true])
        ->set('fileName', 'site')
        ->set('value', "example.com {\n\tbroken\n}")
        ->call('addDynamicConfiguration')
        ->assertDispatched('error', fn (string $event, array $params) => ! str_contains($params[0], 'labels of the proxy container'))
        ->assertNotDispatched('success')
        ->assertNotDispatched('close-modal');

    Process::assertRan(fn ($process) => str_contains(remoteProcessScript($process), "rm -f '{$this->dynamicFile}'"));
});

it('restores the previous content of an edited file that Caddy rejects', function () {
    $previous = "example.com {\n\trespond 200\n}\n";
    fakeCaddyProxy(['reload_fails' => true, 'current_file' => $previous]);

    Livewire::test(NewDynamicConfiguration::class, ['server_id' => $this->server->id, 'fileName' => 'site.caddy', 'value' => $previous, 'newFile' => false])
        ->set('value', "example.com {\n\tbroken\n}")
        ->call('addDynamicConfiguration')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    expect(caddyFileWrites($this->dynamicFile))->toBe(["example.com {\n\tbroken\n}", $previous]);
    Process::assertNotRan(fn ($process) => str_contains(remoteProcessScript($process), 'rm -f'));
});

it('does not change an edited file when its current content cannot be read', function () {
    fakeCaddyProxy(['reload_fails' => true, 'read_fails' => true]);

    Livewire::test(NewDynamicConfiguration::class, ['server_id' => $this->server->id, 'fileName' => 'site.caddy', 'value' => 'old', 'newFile' => false])
        ->set('value', "example.com {\n\tbroken\n}")
        ->call('addDynamicConfiguration')
        ->assertDispatched('error')
        ->assertNotDispatched('success');

    Process::assertNotRan(fn ($process) => str_contains(remoteProcessScript($process), 'tee ') || str_contains(remoteProcessScript($process), 'rm -f'));
});

it('saves the file without a reload when the proxy is not running', function () {
    fakeCaddyProxy(['proxy_state' => 'exited']);

    Livewire::test(NewDynamicConfiguration::class, ['server_id' => $this->server->id, 'newFile' => true])
        ->set('fileName', 'site')
        ->set('value', "example.com {\n\trespond 200\n}")
        ->call('addDynamicConfiguration')
        ->assertDispatched('success');

    expect(caddyFileWrites($this->dynamicFile))->toBe(["example.com {\n\trespond 200\n}"]);
    Process::assertNotRan(fn ($process) => str_contains(remoteProcessScript($process), 'caddy reload'));
});
