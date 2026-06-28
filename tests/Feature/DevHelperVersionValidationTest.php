<?php

use App\Livewire\Settings\Index;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::unguarded(function () {
        $this->rootTeam = Team::find(0) ?? Team::create(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
        if (! Server::find(0)) {
            Server::factory()->create(['id' => 0, 'team_id' => $this->rootTeam->id]);
        }
        if (! InstanceSettings::find(0)) {
            InstanceSettings::create(['id' => 0]);
        }
    });
    Once::flush();

    $this->user = User::factory()->create();
    $this->rootTeam->members()->attach($this->user->id, ['role' => 'admin']);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->rootTeam->id]]);
});

test('dev_helper_version rejects values outside Docker tag grammar on save', function () {
    $invalid = [
        'latest with spaces',
        'a$b',
        'a`b',
        'a|b',
        'a;b',
        'a&b',
        'a>b',
        'a<b',
        "a\nb",
        '.bad',
        '-rm',
    ];

    foreach ($invalid as $payload) {
        Livewire::test(Index::class)
            ->set('dev_helper_version', $payload)
            ->call('instantSave')
            ->assertHasErrors(['dev_helper_version']);
    }

    expect(InstanceSettings::find(0)->dev_helper_version)->toBeNull();
});

test('dev_helper_version accepts valid docker tag formats', function () {
    $valid = ['1.0.12', 'latest', 'dev', 'dev-branch_2', 'v1.2.3-rc1', '1_0_0'];

    foreach ($valid as $tag) {
        Livewire::test(Index::class)
            ->set('dev_helper_version', $tag)
            ->call('instantSave')
            ->assertHasNoErrors(['dev_helper_version']);

        expect(InstanceSettings::find(0)->fresh()->dev_helper_version)->toBe($tag);
    }
});

test('buildHelperImage refuses when non-dev environment', function () {
    config(['app.env' => 'production']);

    Livewire::test(Index::class)
        ->set('dev_helper_version', 'latest')
        ->call('buildHelperImage')
        ->assertDispatched('error');
});

test('buildHelperImage refuses previously stored invalid version', function () {
    config(['app.env' => 'local']);

    $settings = InstanceSettings::find(0);
    $settings->forceFill(['dev_helper_version' => 'bad value'])->saveQuietly();

    Livewire::test(Index::class)
        ->call('buildHelperImage')
        ->assertDispatched('error');
});
