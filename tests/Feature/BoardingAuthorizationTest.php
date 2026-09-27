<?php

use App\Livewire\Boarding\Index;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->rootTeam = Team::factory()->create(['id' => 0, 'show_boarding' => true]);
    $this->localhostKey = PrivateKey::withoutEvents(
        fn () => PrivateKey::factory()->create(['id' => 0, 'uuid' => new_public_id(), 'team_id' => 0])
    );
    $this->localhost = Server::factory()->create([
        'id' => 0,
        'team_id' => 0,
        'private_key_id' => 0,
        'proxy' => ['type' => 'traefik'],
    ]);

    $this->rootOwner = User::factory()->create();
    $this->rootOwner->teams()->attach($this->rootTeam, ['role' => 'owner']);

    $this->rootAdmin = User::factory()->create();
    $this->rootAdmin->teams()->attach($this->rootTeam, ['role' => 'admin']);

    $this->rootMember = User::factory()->create();
    $this->rootMember->teams()->attach($this->rootTeam, ['role' => 'member']);

    $this->otherTeam = Team::factory()->create(['show_boarding' => true]);
    $this->otherOwner = User::factory()->create();
    $this->otherOwner->teams()->attach($this->otherTeam, ['role' => 'owner']);
    $this->otherKey = PrivateKey::withoutEvents(
        fn () => PrivateKey::factory()->create(['uuid' => new_public_id(), 'team_id' => $this->otherTeam->id])
    );
    $this->otherServer = Server::factory()->create([
        'team_id' => $this->otherTeam->id,
        'private_key_id' => $this->otherKey->id,
    ]);
});

function actAsBoardingUser(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

test('root team owners and admins can select the localhost server during onboarding', function (User $user) {
    actAsBoardingUser($user, $this->rootTeam);

    Livewire::test(Index::class, [
        'selectedServerType' => 'localhost',
        'selectedExistingServer' => 0,
    ])
        ->assertOk()
        ->assertSet('createdServer.id', 0);
})->with([
    'owner' => fn () => $this->rootOwner,
    'admin' => fn () => $this->rootAdmin,
]);

test('root team owners and admins can update the localhost server during onboarding', function (User $user) {
    actAsBoardingUser($user, $this->rootTeam);

    Livewire::test(Index::class)
        ->set('createdServer', $this->localhost)
        ->call('selectProxy', 'none')
        ->assertOk();

    expect($this->localhost->fresh()->proxy->type)->toBe('none');
})->with([
    'owner' => fn () => $this->rootOwner,
    'admin' => fn () => $this->rootAdmin,
]);

test('a root team member cannot read the localhost server through onboarding query parameters', function () {
    actAsBoardingUser($this->rootMember, $this->rootTeam);

    Livewire::test(Index::class, [
        'selectedServerType' => 'localhost',
        'selectedExistingServer' => 0,
    ])->assertForbidden();
});

test('an owner from another team cannot read the localhost server through onboarding query parameters', function () {
    actAsBoardingUser($this->otherOwner, $this->otherTeam);

    Livewire::test(Index::class, [
        'selectedServerType' => 'localhost',
        'selectedExistingServer' => 0,
    ])->assertForbidden();
});

test('unauthorized users cannot invoke localhost onboarding actions directly', function (string $role, string $method) {
    $user = $role === 'member' ? $this->rootMember : $this->otherOwner;
    $team = $role === 'member' ? $this->rootTeam : $this->otherTeam;
    actAsBoardingUser($user, $team);

    $before = $this->localhost->fresh()->getAttributes();

    $component = Livewire::test(Index::class)
        ->set('createdServer', $this->localhost);

    if ($method === 'selectProxy') {
        $component->call($method, 'none')->assertForbidden();
    } elseif ($method === 'setServerType') {
        $component->call($method, 'localhost')->assertForbidden();
    } else {
        $component->set('remoteServerPort', 2222)
            ->set('remoteServerUser', 'attacker')
            ->call($method)
            ->assertForbidden();
    }

    expect($this->localhost->fresh()->getAttributes())->toBe($before);
})->with([
    'root member proxy mutation' => ['member', 'selectProxy'],
    'cross-team proxy mutation' => ['other', 'selectProxy'],
    'root member validation' => ['member', 'validateServer'],
    'cross-team prerequisite callback' => ['other', 'handlePrerequisitesInstalled'],
    'root member install dispatch' => ['member', 'installServer'],
    'cross-team SSH mutation' => ['other', 'saveAndValidateServer'],
    'cross-team localhost selection' => ['other', 'setServerType'],
]);

test('client supplied server and private key identifiers from another team are not loaded', function () {
    actAsBoardingUser($this->rootOwner, $this->rootTeam);

    Livewire::test(Index::class, [
        'selectedServerType' => 'remote',
        'selectedExistingServer' => $this->otherServer->id,
        'selectedExistingPrivateKey' => $this->otherKey->id,
    ])
        ->assertSet('createdServer', null)
        ->assertSet('createdPrivateKey', null);
});

test('a client supplied private key identifier from another team is rejected by the action', function () {
    actAsBoardingUser($this->rootOwner, $this->rootTeam);

    Livewire::test(Index::class)
        ->set('selectedExistingPrivateKey', $this->otherKey->id)
        ->call('selectExistingPrivateKey');
})->throws(ModelNotFoundException::class);

test('a hydrated server model from another team is forbidden before use', function () {
    actAsBoardingUser($this->rootOwner, $this->rootTeam);

    Livewire::test(Index::class)
        ->set('createdServer', $this->otherServer)
        ->call('selectProxy', 'none')
        ->assertForbidden();
});

test('a hydrated private key model from another team is rejected before use', function () {
    actAsBoardingUser($this->rootOwner, $this->rootTeam);

    Livewire::test(Index::class)
        ->set('createdPrivateKey', $this->otherKey)
        ->set('privateKey', $this->otherKey->private_key)
        ->set('remoteServerName', 'Injected key server')
        ->set('remoteServerHost', '192.0.2.50')
        ->set('remoteServerPort', 22)
        ->set('remoteServerUser', 'root')
        ->call('saveServer');
})->throws(ModelNotFoundException::class);

test('members cannot directly create projects through onboarding', function () {
    actAsBoardingUser($this->rootMember, $this->rootTeam);

    Livewire::test(Index::class)
        ->call('createNewProject')
        ->assertForbidden();

    expect($this->rootTeam->projects()->count())->toBe(0);
});
