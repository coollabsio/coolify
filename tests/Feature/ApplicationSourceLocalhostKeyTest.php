<?php

use App\Livewire\Project\Application\Source;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
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

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function applicationSourceValidPrivateKey(): string
{
    return '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----';
}

describe('Application Source with localhost key (id=0)', function () {
    test('renders deploy key section when private_key_id is 0', function () {
        $privateKey = PrivateKey::create([
            'id' => 0,
            'name' => 'localhost',
            'private_key' => applicationSourceValidPrivateKey(),
            'team_id' => $this->team->id,
        ]);

        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'private_key_id' => 0,
        ]);

        Livewire::test(Source::class, ['application' => $application])
            ->assertSuccessful()
            ->assertSet('privateKeyId', 0)
            ->assertSee('Deploy Key');
    });

    test('shows no source connected section when private_key_id is null', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'private_key_id' => null,
        ]);

        Livewire::test(Source::class, ['application' => $application])
            ->assertSuccessful()
            ->assertSet('privateKeyId', null)
            ->assertDontSee('Deploy Key')
            ->assertSee('No source connected');
    });

    test('dispatches configuration changed when source settings are saved', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
            'git_commit_sha' => 'HEAD',
        ]);

        Livewire::test(Source::class, ['application' => $application])
            ->set('gitBranch', 'next')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('configurationChanged');
    });
});
