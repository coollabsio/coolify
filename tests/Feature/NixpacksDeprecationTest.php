<?php

use App\Livewire\Project\Application\General;
use App\Livewire\Project\Application\Heading;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('the general page does not repeat the nixpacks deprecation notice from the heading', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'static_image' => 'nginx:alpine',
        'base_directory' => '/',
        'ports_exposes' => '3000',
        'is_http_basic_auth_enabled' => false,
        'redirect' => 'no',
    ]);

    Livewire::test(General::class, ['application' => $application])
        ->assertSuccessful()
        ->assertDontSee(config('deprecations.nixpacks'));
});

test('switching the build pack to railpack hides the nixpacks deprecation notice in the heading', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
    ]);

    $component = Livewire::test(Heading::class, ['application' => $application])
        ->assertSee(config('deprecations.nixpacks'));

    $application->update(['build_pack' => 'railpack']);

    $component->call('$refresh')->assertDontSee(config('deprecations.nixpacks'));
});

test('the application heading shows the nixpacks deprecation notice only for nixpacks', function (string $buildPack, bool $showsNotice) {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => $buildPack,
    ]);

    $component = Livewire::test(Heading::class, ['application' => $application])->assertSuccessful();

    $showsNotice
        ? $component->assertSee(config('deprecations.nixpacks'))->assertSee('Switch to Railpack')
        : $component->assertDontSee(config('deprecations.nixpacks'));
})->with([
    'nixpacks' => ['nixpacks', true],
    'railpack' => ['railpack', false],
]);
