<?php

use App\Actions\Proxy\StopProxy;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChanged;
use App\Events\ProxyStatusChangedUI;
use App\Jobs\RefreshDefaultRedirectJob;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

// Servers stay in a static identity map; do not leak them into later test files.
afterEach(fn () => Server::flushIdentityMap());

beforeEach(function () {
    Server::flushIdentityMap();
    Event::fake([ProxyStatusChanged::class, ProxyStatusChangedUI::class]);
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);
    fakeProxyFileUploads();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'user' => 'cooluser',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
});

function useErrorPageProxy(Server $server, string $proxyType, array $proxy = []): Server
{
    $server->proxy->type = $proxyType;
    foreach ($proxy as $key => $value) {
        $server->proxy->{$key} = $value;
    }
    $server->save();

    return $server->refresh();
}

/**
 * @return array<int, string>
 */
function errorPageScriptLines(): array
{
    $lines = [];
    Process::assertRan(function ($process) use (&$lines) {
        array_push($lines, ...explode("\n", remoteProcessScript($process)));

        return true;
    });

    return $lines;
}

it('routes the Traefik catch-all to the 404 script of the page container', function () {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value);

    $config = Yaml::parse($server->defaultRedirectConfiguration());

    expect($config['http']['routers']['catchall']['service'])->toBe('error-pages')
        ->and($config['http']['routers']['catchall']['middlewares'])->toBe(['error-page-404'])
        ->and($config['http']['middlewares']['error-page-404'])->toBe(['replacePath' => ['path' => '/cgi-bin/404']])
        ->and($config['http']['services'])->toBe([
            'error-pages' => ['loadBalancer' => ['servers' => [['url' => 'http://coolify-proxy-error-pages:80']]]],
        ]);
});

it('catches plain HTTP with a router without TLS', function (array $proxy, string $service, array $middlewares) {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value, $proxy);

    $routers = Yaml::parse($server->defaultRedirectConfiguration())['http']['routers'];

    // A router with `tls` matches only HTTPS, so plain HTTP needs the catchall-http router.
    expect($routers['catchall']['entryPoints'])->toBe(['https'])
        ->and($routers['catchall']['tls'])->toBe(['certResolver' => 'letsencrypt'])
        ->and($routers['catchall-http'])->toBe([
            'entryPoints' => ['http'],
            'service' => $service,
            'rule' => 'PathPrefix(`/`)',
            'priority' => -1000,
            'middlewares' => $middlewares,
        ]);
})->with([
    'error page' => [[], 'error-pages', ['error-page-404']],
    'redirect URL' => [['redirect_url' => 'https://example.com'], 'noop', ['redirect-regexp']],
]);

it('serves the error page through the 404 script on Caddy', function () {
    $server = useErrorPageProxy($this->server, ProxyTypes::CADDY->value);

    expect($server->defaultRedirectConfiguration())
        ->toContain('reverse_proxy coolify-proxy-error-pages:80 {')
        ->toContain('rewrite /cgi-bin/404')
        ->not->toContain('respond 404');
});

it('keeps the redirect URL ahead of the error page', function (string $proxyType) {
    $server = useErrorPageProxy($this->server, $proxyType, ['redirect_url' => 'https://example.com']);
    $server->proxy_error_page = '<h1>custom</h1>';
    $server->save();

    $configuration = $server->defaultRedirectConfiguration();
    $server->setupDefaultRedirect();
    $lines = errorPageScriptLines();

    expect($configuration)->toContain('https://example.com')->not->toContain('error-pages')
        ->and($lines)->toContain('sudo docker rm -f coolify-proxy-error-pages >/dev/null 2>&1 || sudo true')
        ->and(implode("\n", $lines))->not->toContain('docker run');
})->with([ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value]);

it('removes the catch-all and the page container when the default redirect is disabled', function () {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value, ['redirect_enabled' => false]);

    $server->setupDefaultRedirect();
    $lines = errorPageScriptLines();

    expect($lines)
        ->toContain('sudo rm -f /data/coolify/proxy//dynamic/default_redirect_404.yaml')
        ->toContain('sudo docker rm -f coolify-proxy-error-pages >/dev/null 2>&1 || sudo true')
        ->and(writtenProxyFile($lines, '/data/coolify/proxy//dynamic/default_redirect_404.yaml'))->toBeNull();
});

it('starts the page container through sudo before it writes the catch-all', function (string $proxyType, string $catchAll) {
    $server = useErrorPageProxy($this->server, $proxyType);

    $server->setupDefaultRedirect();
    $lines = errorPageScriptLines();

    $runLine = collect($lines)->search(fn (string $line) => str_contains($line, 'docker run -d --name coolify-proxy-error-pages'));
    $catchAllLine = collect($lines)->search(fn (string $line) => str_contains($line, "tee $catchAll"));

    expect($runLine)->toBeInt()
        ->and($catchAllLine)->toBeInt()
        ->and($runLine)->toBeLessThan($catchAllLine)
        ->and($lines[$runLine])->toBe('sudo docker start coolify-proxy-error-pages >/dev/null 2>&1 || sudo docker run -d --name coolify-proxy-error-pages --network coolify --restart unless-stopped --label coolify.managed=true -v /data/coolify/proxy/error-pages:/www:ro busybox:musl httpd -f -p 80 -h /www')
        ->and($lines)->toContain('sudo mkdir -p /data/coolify/proxy/error-pages && sudo find /data/coolify/proxy/error-pages -user root -exec chown -h cooluser:cooluser {} + && sudo chmod o-rwx /data/coolify/proxy/error-pages')
        // The page goes through scp, then sudo moves it into place.
        ->and(collect($lines)->contains(fn (string $line) => preg_match('#^sudo mv -f /tmp/coolify-upload-[0-9a-f]+ /data/coolify/proxy/error-pages/404.html$#', $line) === 1))->toBeTrue()
        ->and($lines)->toContain('sudo chmod 644 /data/coolify/proxy/error-pages/404.html')
        ->and(writtenProxyFile($lines, '/data/coolify/proxy/error-pages/404.html'))->toContain('There is no site at this address')
        ->and(writtenProxyFile($lines, '/data/coolify/proxy/error-pages/cgi-bin/404'))
        ->toBe("#!/bin/sh\nprintf 'HTTP/1.1 404 Not Found\\r\\nContent-Type: text/html; charset=utf-8\\r\\nCache-Control: no-store\\r\\n\\r\\n'\ncat /www/404.html\n")
        ->and(writtenProxyFile($lines, '/data/coolify/proxy/error-pages/503.html'))->toContain('This site is not available at the moment')
        ->and(writtenProxyFile($lines, '/data/coolify/proxy/error-pages/cgi-bin/503'))
        ->toBe("#!/bin/sh\nprintf 'HTTP/1.1 503 Service Unavailable\\r\\nContent-Type: text/html; charset=utf-8\\r\\nCache-Control: no-store\\r\\n\\r\\n'\ncat /www/503.html\n")
        ->and($lines)->toContain('sudo chmod 755 /data/coolify/proxy/error-pages/cgi-bin/404 /data/coolify/proxy/error-pages/cgi-bin/503')
        ->and(writtenProxyFile($lines, $catchAll))->toContain('coolify-proxy-error-pages');
})->with([
    'traefik' => [ProxyTypes::TRAEFIK->value, '/data/coolify/proxy//dynamic/default_redirect_404.yaml'],
    'caddy' => [ProxyTypes::CADDY->value, '/data/coolify/proxy/caddy/dynamic/default_redirect_404.caddy'],
]);

it('writes the current catch-all and its hash when the refresh job runs', function () {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value);
    $server->proxy->set('default_redirect_hash', null);
    $server->save();

    (new RefreshDefaultRedirectJob($server))->handle();
    $lines = errorPageScriptLines();

    expect($lines)->toContain('sudo rm -f /data/coolify/proxy//dynamic/default_redirect_503.yaml')
        ->and(writtenProxyFile($lines, '/data/coolify/proxy//dynamic/default_redirect_404.yaml'))->toContain('/cgi-bin/404')
        ->and($server->fresh()->proxy->get('default_redirect_hash'))->toBe(md5($server->defaultRedirectConfiguration()))
        ->and($server->fresh()->claimOutdatedDefaultRedirectRefresh())->toBeFalse();
});

it('pushes the custom page and falls back to the default page after a reset', function () {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value);
    $server->proxy_error_page = '<h1>Maintenance</h1>';
    $server->save();

    $server->setupDefaultRedirect();
    expect(writtenProxyFile(errorPageScriptLines(), '/data/coolify/proxy/error-pages/404.html'))->toBe('<h1>Maintenance</h1>');

    $server->proxy_error_page = null;
    $server->save();
    $server->setupDefaultRedirect();

    expect(writtenProxyFile(errorPageScriptLines(), '/data/coolify/proxy/error-pages/404.html'))
        ->toContain('There is no site at this address')
        ->toContain('https://coolify.io/docs/troubleshoot/applications/no-available-server')
        ->not->toContain($server->name)
        ->not->toContain($server->ip)
        ->not->toContain($this->team->name);
});

it('leaves the 404 to Traefik on Swarm servers, without a catch-all or a page container', function (string $swarmSetting) {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value);
    $server->settings->update([$swarmSetting => true]);
    $server->refresh();

    $configuration = $server->defaultRedirectConfiguration();
    $server->setupDefaultRedirect();
    $lines = errorPageScriptLines();

    expect($configuration)->toBeNull()
        ->and($lines)->toContain('sudo rm -f /data/coolify/proxy//dynamic/default_redirect_404.yaml')
        ->and(implode("\n", $lines))->not->toContain('coolify-proxy-error-pages');
})->with(['is_swarm_manager', 'is_swarm_worker']);

it('removes the page container when the proxy stops', function () {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value);

    StopProxy::run($server);

    expect(errorPageScriptLines())->toContain('sudo docker rm -f coolify-proxy-error-pages 2>/dev/null || sudo true');
});

function errorPageUser(Team $team, string $role): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);

    return $user;
}

it('lets an admin save and reset the custom error page', function () {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value);
    $this->actingAs(errorPageUser($this->team, 'admin'));
    session(['currentTeam' => $this->team]);

    // The page loads the proxy configuration before the global save bar submits it.
    $component = Livewire::test('server.proxy', ['server' => $server])
        ->set('proxySettings', "services:\n  traefik:\n    image: traefik:v3.6\n    ports:\n      - '80:80'\n")
        ->set('customErrorPage', '<h1>Maintenance</h1>')
        ->call('submit')
        ->assertDispatched('success');

    expect($server->fresh()->proxy_error_page)->toBe('<h1>Maintenance</h1>')
        ->and(writtenProxyFile(errorPageScriptLines(), '/data/coolify/proxy/error-pages/404.html'))->toBe('<h1>Maintenance</h1>');

    $component->call('resetCustomErrorPage')
        ->assertDispatched('success')
        ->assertSet('customErrorPage', null);

    expect($server->fresh()->proxy_error_page)->toBeNull();
});

it('uploads a custom page larger than one shell argument', function () {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value);
    $page = '<h1>Big</h1>'.str_repeat('a', 300 * 1024);
    $server->proxy_error_page = $page;
    $server->save();

    $server->setupDefaultRedirect();
    $lines = errorPageScriptLines();

    // Linux limits one shell argument to 128 KiB, so the page must not be inside a command.
    expect(writtenProxyFile($lines, '/data/coolify/proxy/error-pages/404.html'))->toBe($page)
        ->and(collect($lines)->max(fn (string $line) => strlen($line)))->toBeLessThan(128 * 1024);
});

it('rejects a custom error page larger than the limit', function () {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value);
    $this->actingAs(errorPageUser($this->team, 'owner'));
    session(['currentTeam' => $this->team]);

    Livewire::test('server.proxy', ['server' => $server])
        ->set('customErrorPage', str_repeat('a', Server::PROXY_ERROR_PAGE_MAX_BYTES + 1))
        ->call('submit')
        ->assertDispatched('error');

    expect($server->fresh()->proxy_error_page)->toBeNull();
    Process::assertNothingRan();
});

it('does not let a member or another team change the custom error page', function (string $who) {
    $server = useErrorPageProxy($this->server, ProxyTypes::TRAEFIK->value);
    $server->proxy_error_page = '<h1>Original</h1>';
    $server->save();

    $team = $who === 'member' ? $this->team : Team::factory()->create();
    $this->actingAs(errorPageUser($team, $who === 'member' ? 'member' : 'owner'));
    session(['currentTeam' => $team]);

    Livewire::test('server.proxy', ['server' => $server])
        ->set('customErrorPage', '<h1>Hacked</h1>')
        ->call('submit')
        ->assertDispatched('error')
        ->call('resetCustomErrorPage')
        ->assertDispatched('error');

    expect($server->fresh()->proxy_error_page)->toBe('<h1>Original</h1>');
    Process::assertNothingRan();
})->with(['member', 'other team']);
