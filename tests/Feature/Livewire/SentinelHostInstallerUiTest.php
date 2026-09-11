<?php

use App\Actions\Server\InstallSentinelHost;
use App\Livewire\Server\Sentinel;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->server = Server::factory()->create(['team_id' => $user->teams()->firstOrFail()->id]);
});

it('shows and runs the host installer in the gated development environment', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    InstallSentinelHost::partialMock()->shouldReceive('handle')->once()->with(Mockery::type(Server::class))->andReturn('');

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertSee('Install host Sentinel')
        ->call('installHostSentinel')
        ->assertDispatched('success', 'Host Sentinel installed and started.');
});

it('hides and blocks the host installer outside development', function () {
    config()->set('app.env', 'production');
    config()->set('constants.sentinel.host_enabled', true);
    InstallSentinelHost::partialMock()->shouldReceive('handle')->never();

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertDontSee('Install host Sentinel')
        ->call('installHostSentinel')
        ->assertNotFound();
});

it('hides the host installer when its gate is disabled', function () {
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', false);

    Livewire::test(Sentinel::class, ['server' => $this->server])
        ->assertDontSee('Install host Sentinel');
});

it('shows the host installer button without an icon', function () {
    $view = file_get_contents(resource_path('views/livewire/server/sentinel.blade.php'));

    preg_match('/<x-forms\.button[^>]+wire:click="installHostSentinel"[^>]*>(?<content>.*?)<\/x-forms\.button>/s', $view, $matches);

    expect($matches['content'] ?? '')
        ->toContain('Install host Sentinel')
        ->not->toContain('<x-reicon');
});
