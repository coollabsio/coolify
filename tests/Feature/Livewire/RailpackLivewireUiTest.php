<?php

use App\Livewire\Project\Application\General;
use App\Livewire\Project\New\PublicGitRepository;
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
});

describe('PublicGitRepository port handling for railpack', function () {
    test('switching to railpack resets port to 3000 when not static', function () {
        Livewire::test(PublicGitRepository::class, ['type' => 'public'])
            ->set('build_pack', 'dockerfile')
            ->assertSet('port', 3000)
            ->set('build_pack', 'railpack')
            ->assertSet('port', 3000);
    });

    test('switching to railpack preserves port when isStatic is true', function () {
        $component = Livewire::test(PublicGitRepository::class, ['type' => 'public'])
            ->set('isStatic', true)
            ->call('instantSave');

        // After instantSave with isStatic=true, port becomes 80
        $component->assertSet('port', 80);

        // Switching from nixpacks to railpack should NOT clobber port back to 3000
        $component->set('build_pack', 'railpack')
            ->assertSet('port', 80);
    });

    test('switching to static sets port to 80 and disables show_is_static', function () {
        Livewire::test(PublicGitRepository::class, ['type' => 'public'])
            ->set('build_pack', 'static')
            ->assertSet('port', 80)
            ->assertSet('isStatic', false)
            ->assertSet('show_is_static', false);
    });
});

describe('General view railpack helper text', function () {
    beforeEach(function () {
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

    test('railpack app shows railpack.json helper text and not nixpacks.toml', function () {
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
            ->assertSee('railpack.json')
            ->assertDontSee('nixpacks.toml');
    });

    test('nixpacks app shows nixpacks.toml helper text and not railpack.json', function () {
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
            ->assertSee('nixpacks.toml')
            ->assertDontSee('railpack.json');
    });
});
