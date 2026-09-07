<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\CloudProviderToken;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    InstanceSettings::unguarded(function () {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    });

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $this->team->id,
    ]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id, 'network' => 'coolify-test']);
});

test('existing application buildpack selector lists nixpacks before railpack', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->assertSeeInOrder([
            '<option value="nixpacks">Nixpacks</option>',
            '<option value="railpack">Railpack (Beta)</option>',
        ], false);
});

test('existing application shows railpack beta badge in build helper copy', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'railpack',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->assertSee('Railpack')
        ->assertSee('Beta');
});

test('saving application domains creates missing Cloudflare DNS records when enabled on server', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
    ]);
    $this->server->settings->update([
        'cloudflare_dns_enabled' => true,
        'cloudflare_dns_token_id' => $token->id,
        'cloudflare_dns_proxied' => false,
    ]);

    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);
        $zoneName = $query['name'] ?? null;

        if (str_contains($request->url(), '/zones?') && $zoneName === 'app.example.com') {
            return Http::response(['success' => true, 'result' => []], 200);
        }

        if (str_contains($request->url(), '/zones?') && $zoneName === 'example.com') {
            return Http::response(['success' => true, 'result' => [['id' => 'zone-id', 'name' => 'example.com']]], 200);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/zones/zone-id/dns_records')) {
            return Http::response(['success' => true, 'result' => []], 200);
        }

        if ($request->method() === 'POST' && str_contains($request->url(), '/zones/zone-id/dns_records')) {
            return Http::response(['success' => true, 'result' => ['id' => 'record-id']], 200);
        }

        return Http::response(['success' => false], 500);
    });

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);

    Livewire::test(General::class, ['application' => $application])
        ->set('fqdn', 'https://app.example.com')
        ->call('submit')
        ->assertDispatched('success');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_contains($request->url(), '/zones/zone-id/dns_records')
        && $request['name'] === 'app.example.com'
        && $request['content'] === $this->server->ip);
});
