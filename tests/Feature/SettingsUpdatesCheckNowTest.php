<?php

use App\Jobs\CheckTraefikVersionJob;
use App\Livewire\Settings\Updates;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([CheckTraefikVersionJob::class]);
    config()->set('constants.coolify.version', '4.4.6');

    $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
    Server::factory()->create(['id' => 0, 'team_id' => $rootTeam->id]);
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $user = User::factory()->create();
    $rootTeam->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => $rootTeam->id]]);
});

it('reports a failed CDN request instead of "No new version available"', function () {
    Http::fake(['*' => Http::response('error code: 1200', 503)]);
    File::partialMock()->shouldReceive('put')->with(base_path('versions.json'), Mockery::any())->never();

    Livewire::test(Updates::class)
        ->call('checkManually')
        ->assertDispatched('error', 'Could not check for updates. Try again later.')
        ->assertNotDispatched('success');
});

it('reports a new version when the CDN has a newer version', function () {
    Http::fake(['*' => Http::response(['coolify' => ['v4' => ['version' => '4.4.7']]])]);
    File::partialMock()->shouldReceive('put')->with(base_path('versions.json'), Mockery::any())->once();

    Livewire::test(Updates::class)
        ->call('checkManually')
        ->assertDispatched('success', 'New version available!');

    expect(InstanceSettings::get()->new_version_available)->toBeTruthy();
});
