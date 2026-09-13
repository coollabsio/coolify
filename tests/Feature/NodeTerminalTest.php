<?php

use App\Livewire\Project\Shared\ExecuteContainerCommand;
use App\Livewire\Project\Shared\Terminal;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\PrivateKey;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.ssh.mux_enabled', false);

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Storage::fake('ssh-keys');
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    Storage::disk('ssh-keys')->put(
        "ssh_key@{$this->privateKey->uuid}",
        $this->privateKey->private_key,
    );

    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'name' => 'Terminal Node',
        'ip' => '192.0.2.10',
        'is_reachable' => true,
        'is_usable' => true,
    ]);
});

it('shows an authorized terminal menu on the node view', function () {
    $this->get(route('node.show', $this->node->uuid))
        ->assertSuccessful()
        ->assertSee('General')
        ->assertSee('Terminal')
        ->assertSee(route('node.command', $this->node->uuid), false);
});

it('opens the node terminal through the shared terminal page', function () {
    $this->get(route('node.command', $this->node->uuid))
        ->assertSuccessful()
        ->assertSeeLivewire(ExecuteContainerCommand::class)
        ->assertSee('Terminal Node')
        ->assertSee('Terminal');
});

it('does not expose the node terminal to team members', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->get(route('node.show', $this->node->uuid))
        ->assertSuccessful()
        ->assertDontSee(route('node.command', $this->node->uuid), false);

    $this->get(route('node.command', $this->node->uuid))->assertForbidden();
});

it('does not expose a foreign node through the terminal route', function () {
    $foreignTeam = User::factory()->create()->teams()->firstOrFail();
    $this->user->teams()->attach($foreignTeam, ['role' => 'member']);
    $foreignNode = Node::factory()->create([
        'team_id' => $foreignTeam->id,
        'private_key_id' => $this->privateKey->id,
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $this->get(route('node.command', $foreignNode->uuid))->assertNotFound();
});

it('does not expose a foreign node overview through the current team', function () {
    $foreignTeam = User::factory()->create()->teams()->firstOrFail();
    $this->user->teams()->attach($foreignTeam, ['role' => 'member']);
    $this->user->unsetRelation('teams');
    $foreignNode = Node::factory()->create([
        'team_id' => $foreignTeam->id,
        'private_key_id' => $this->privateKey->id,
    ]);

    $this->get(route('node.show', $foreignNode->uuid))->assertNotFound();
});

it('keeps the node terminal behind the development feature gate', function () {
    config()->set('constants.sentinel.host_enabled', false);

    $this->get(route('node.command', $this->node->uuid))->assertNotFound();
});

it('generates an ssh terminal command for an authorized node', function () {
    Livewire::test(Terminal::class)
        ->dispatch(
            'send-terminal-command',
            isContainer: false,
            identifier: $this->node->name,
            serverUuid: $this->node->uuid,
            targetType: 'node',
        )
        ->assertDispatched('send-back-command', function (string $event, array $parameters): bool {
            return str_contains($parameters[0], "'root'@'192.0.2.10'");
        });
});

it('rejects terminal commands for a node that is not ready', function () {
    $this->node->update(['is_usable' => false]);

    Livewire::test(Terminal::class)
        ->dispatch(
            'send-terminal-command',
            isContainer: false,
            identifier: $this->node->name,
            serverUuid: $this->node->uuid,
            targetType: 'node',
        )
        ->assertForbidden();
});

it('rejects terminal commands for a foreign node', function () {
    $foreignNode = Node::factory()->create([
        'private_key_id' => $this->privateKey->id,
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    expect(fn () => Livewire::test(Terminal::class)->dispatch(
        'send-terminal-command',
        isContainer: false,
        identifier: $foreignNode->name,
        serverUuid: $foreignNode->uuid,
        targetType: 'node',
    ))->toThrow(ModelNotFoundException::class);
});

it('rejects container terminal commands for a node', function () {
    Livewire::test(Terminal::class)
        ->dispatch(
            'send-terminal-command',
            isContainer: true,
            identifier: 'container-name',
            serverUuid: $this->node->uuid,
            targetType: 'node',
        )
        ->assertNotFound();
});

it('rejects unknown terminal target types', function () {
    Livewire::test(Terminal::class)
        ->dispatch(
            'send-terminal-command',
            isContainer: false,
            identifier: $this->node->name,
            serverUuid: $this->node->uuid,
            targetType: 'unknown',
        )
        ->assertNotFound();
});

it('lists only ready current-team node addresses for terminal authorization', function () {
    $notReady = Node::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => '192.0.2.11',
        'is_reachable' => true,
        'is_usable' => false,
    ]);
    $foreign = Node::factory()->create([
        'private_key_id' => $this->privateKey->id,
        'ip' => '192.0.2.12',
        'is_reachable' => true,
        'is_usable' => true,
    ]);

    $response = $this->postJson('/terminal/auth/ips');

    $response->assertSuccessful();
    expect($response->json('ipAddresses'))
        ->toContain($this->node->ip)
        ->not->toContain($notReady->ip)
        ->not->toContain($foreign->ip);
});
