<?php

use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Livewire\Server\Navbar as ServerNavbar;
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
