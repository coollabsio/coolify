<?php

use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Livewire\Server\Create as ServerCreate;
use App\Livewire\Server\Index as ServerIndex;
use App\Livewire\Server\Navbar as ServerNavbar;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
    ]);

    $this->server->settings()->update([
        'is_reachable' => true,
        'is_usable' => true,
    ]);
});

// --- Server Policy: update ---

test('admin can update server', function () {
    expect($this->admin->can('update', $this->server))->toBeTrue();
});

test('member cannot update server', function () {
    expect($this->member->can('update', $this->server))->toBeFalse();
});

// --- Server Policy: delete ---

test('admin can delete server', function () {
    expect($this->admin->can('delete', $this->server))->toBeTrue();
});

test('member cannot delete server', function () {
    expect($this->member->can('delete', $this->server))->toBeFalse();
});

// --- Server Policy: view ---

test('admin can view server', function () {
    expect($this->admin->can('view', $this->server))->toBeTrue();
});

test('member can view server', function () {
    expect($this->member->can('view', $this->server))->toBeTrue();
});

// --- Server Policy: manageProxy ---

test('admin can manage proxy', function () {
    expect($this->admin->can('manageProxy', $this->server))->toBeTrue();
});

test('member cannot manage proxy', function () {
    expect($this->member->can('manageProxy', $this->server))->toBeFalse();
});

// --- Server Policy: manageSentinel ---

test('admin can manage sentinel', function () {
    expect($this->admin->can('manageSentinel', $this->server))->toBeTrue();
});

test('member cannot manage sentinel', function () {
    expect($this->member->can('manageSentinel', $this->server))->toBeFalse();
});

// --- Server Policy: manageCaCertificate ---

test('admin can manage CA certificate', function () {
    expect($this->admin->can('manageCaCertificate', $this->server))->toBeTrue();
});

test('member cannot manage CA certificate', function () {
    expect($this->member->can('manageCaCertificate', $this->server))->toBeFalse();
});

// --- Server Policy: viewSecurity ---

test('admin can view security', function () {
    expect($this->admin->can('viewSecurity', $this->server))->toBeTrue();
});

test('member cannot view security', function () {
    expect($this->member->can('viewSecurity', $this->server))->toBeFalse();
});

// --- Server Policy: create ---

test('admin can create server', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('create', Server::class))->toBeTrue();
});

test('member cannot create server', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('create', Server::class))->toBeFalse();
});

test('admin can access new server page', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $this->get(route('server.create'))
        ->assertSuccessful()
        ->assertSee('New Server')
        ->assertSeeLivewire(ServerCreate::class);
});

test('new server chooser lists providers before rendering a creation form', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServerCreate::class)
        ->assertSee('Hetzner')
        ->assertSee('Vultr')
        ->assertSee('DigitalOcean')
        ->assertSee('Manual')
        ->assertDontSee('>Select<', false)
        ->assertDontSee('Continue')
        ->assertSee(route('server.create.type', ['type' => 'hetzner']))
        ->assertSee(route('server.create.type', ['type' => 'vultr']))
        ->assertSee(route('server.create.type', ['type' => 'digital-ocean']))
        ->assertSee(route('server.create.type', ['type' => 'manual']))
        ->assertDontSee('Add Server by IP Address');
});

test('new server chooser uses compact mobile provider cards', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServerCreate::class)
        ->assertSee('mx-auto flex w-full max-w-7xl flex-col', false)
        ->assertSee('sm:grid', false)
        ->assertSee('sm:grid-cols-2', false)
        ->assertSee('xl:grid-cols-4', false)
        ->assertSee('gap-3 sm:gap-6', false)
        ->assertSee('dark:hover:border-warning', false)
        ->assertSee('focus-visible:border-coollabs', false)
        ->assertSee('aria-label="Choose Hetzner"', false)
        ->assertSee('p-3 sm:p-6', false)
        ->assertDontSee('sm:min-h-80', false)
        ->assertSee('size-9 sm:size-14', false)
        ->assertDontSee('size-9 sm:size-14 w-14 h-14', false)
        ->assertSee('hidden sm:block', false)
        ->assertDontSee('>Select<', false)
        ->assertDontSee('<button', false);
});

test('new server provider pages render the selected creation flow', function (string $type, string $heading) {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $response = $this->get(route('server.create.type', ['type' => $type]))
        ->assertSuccessful()
        ->assertSee($heading)
        ->assertDontSee('<h1>New Server</h1>', false)
        ->assertDontSee('Choose how to add your server');

    $response->assertSeeInOrder([$heading, 'Back']);
})->with([
    ['hetzner', 'Hetzner'],
    ['vultr', 'Vultr'],
    ['digital-ocean', 'DigitalOcean'],
    ['manual', 'Manual'],
]);

test('new server provider pages do not show the new token action in the header', function (string $type, string $heading) {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $response = $this->get(route('server.create.type', ['type' => $type]))
        ->assertSuccessful()
        ->assertDontSee('+ New Token')
        ->assertDontSee('+ Add New Token');

    $response->assertSeeInOrder([$heading, 'Back']);
})->with([
    ['hetzner', 'Hetzner'],
    ['vultr', 'Vultr'],
    ['digital-ocean', 'DigitalOcean'],
]);

test('new server provider pages show the new token action in the header when tokens exist', function (string $type, string $provider, string $heading, string $modalTitle) {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => $provider,
    ]);

    $response = $this->get(route('server.create.type', ['type' => $type]))
        ->assertSuccessful()
        ->assertSee($modalTitle);

    $response->assertSeeInOrder([$heading, 'Back', '+ New Token']);
})->with([
    ['hetzner', 'hetzner', 'Hetzner', 'Add Hetzner Token'],
    ['vultr', 'vultr', 'Vultr', 'Add Vultr Token'],
    ['digital-ocean', 'digitalocean', 'DigitalOcean', 'Add DigitalOcean Token'],
]);

test('new server manual page does not show the new token action', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $this->get(route('server.create.type', ['type' => 'manual']))
        ->assertSuccessful()
        ->assertSee('Manual')
        ->assertDontSee('+ New Token');
});

test('member cannot access new server page', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $this->get(route('server.create'))
        ->assertForbidden();
});

test('server index links admins to new server page', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServerIndex::class)
        ->assertSee(route('server.create'));
});

test('server index does not link members to new server page', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServerIndex::class)
        ->assertDontSee(route('server.create'));
});

// --- Cross-team isolation ---

test('user from different team cannot view server', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('view', $this->server))->toBeFalse();
});

test('user from different team cannot update server', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('update', $this->server))->toBeFalse();
});

// --- Navbar Livewire component actions ---

test('member cannot restart proxy via navbar', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServerNavbar::class, ['server' => $this->server])
        ->call('restart')
        ->assertDispatched('error');
});

test('member cannot check proxy via navbar', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServerNavbar::class, ['server' => $this->server])
        ->call('checkProxy')
        ->assertDispatched('error');
});

test('member cannot start proxy via navbar', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServerNavbar::class, ['server' => $this->server])
        ->call('startProxy')
        ->assertDispatched('error');
});

test('member cannot stop proxy via navbar', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(ServerNavbar::class, ['server' => $this->server])
        ->call('stop')
        ->assertDispatched('error');
});

// --- Terminal access gate ---

test('admin can access terminal', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('canAccessTerminal'))->toBeTrue();
});

test('member cannot access terminal', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('canAccessTerminal'))->toBeFalse();
});

// --- Server page access ---

test('admin can access server page', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $this->get("/server/{$this->server->uuid}")->assertSuccessful();
});

test('member can access server page', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $this->get("/server/{$this->server->uuid}")->assertSuccessful();
});

test('admin can access sentinel configuration and logs pages', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $this->get(route('server.sentinel', ['server_uuid' => $this->server->uuid]))
        ->assertSuccessful();

    $this->get(route('server.sentinel.logs', ['server_uuid' => $this->server->uuid]))
        ->assertSuccessful();
});

test('member cannot access sentinel configuration and logs pages', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $this->get(route('server.sentinel', ['server_uuid' => $this->server->uuid]))
        ->assertForbidden();

    $this->get(route('server.sentinel.logs', ['server_uuid' => $this->server->uuid]))
        ->assertForbidden();
});

test('sentinel navigation is only visible to team admins', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $this->get("/server/{$this->server->uuid}")
        ->assertSuccessful()
        ->assertDontSee(route('server.sentinel', ['server_uuid' => $this->server->uuid]));

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $this->get("/server/{$this->server->uuid}")
        ->assertSuccessful()
        ->assertSee(route('server.sentinel', ['server_uuid' => $this->server->uuid]));
});

test('unauthenticated user cannot access server page', function () {
    $this->get("/server/{$this->server->uuid}")->assertRedirect('/login');
});
