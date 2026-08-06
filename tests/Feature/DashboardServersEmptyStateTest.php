<?php

use App\Livewire\Dashboard;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('shows an add private key button when no private keys exist', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('A private key is required')
        ->assertSee('Add an SSH private key before connecting your first server.')
        ->assertSee('Add private key')
        ->assertSeeHtml('button button-highlighted')
        ->assertSee(route('security.private-key.index'), false);
});

it('shows a new server button when private keys exist but servers do not', function () {
    PrivateKey::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Deploy Key',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('No servers yet')
        ->assertSee('Connect infrastructure for your deployments.')
        ->assertSee('New server')
        ->assertSee(route('server.create'), false)
        ->assertDontSee('A private key is required')
        ->assertDontSee('Add private key');
});

it('does not show server empty state when servers exist', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'name' => 'Production Host',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Production Host')
        ->assertDontSee('A private key is required')
        ->assertDontSee('No servers yet');
});

it('renders clickable server cards without a decorative arrow control', function () {
    $privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
        'name' => 'Production Host',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Production Host')
        ->assertSeeHtml('aria-label="Open Production Host"')
        ->assertSeeHtml(route('server.show', ['server_uuid' => $server->uuid]))
        // Decorative arrow used z-10 over the full-card link and blocked clicks.
        ->assertDontSeeHtml('group-hover:bg-neutral-100 group-hover:text-black dark:text-fg-faint dark:group-hover:bg-white/[0.06]');
});

it('keeps resource grid cards free of decorative open chevrons', function () {
    $view = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));

    expect($view)
        ->toContain('absolute inset-0 rounded-xl')
        ->toContain('aria-label="`Open ${item.name}`"')
        // Decorative card chevrons used a dedicated size-7 open control.
        ->not->toContain('flex size-7 shrink-0 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg');
});

it('hides the add private key button for members without create permission', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Dashboard::class)
        ->assertSee('A private key is required')
        ->assertDontSee('Add private key');
});
