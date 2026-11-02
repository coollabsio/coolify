<?php

use App\Livewire\Security\PrivateKey\Show;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0]);
    Storage::fake('ssh-keys');

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create([
        'team_id' => $this->team->id,
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
    ]);
    AuditEvent::query()->delete();
});

function privateKeyAuditEvents(): array
{
    return AuditEvent::query()->orderBy('id')->pluck('event')->all();
}

it('records one audit event when a key is created from the UI', function () {
    PrivateKey::createAndStore([
        'name' => 'new key',
        'private_key' => PrivateKey::generateNewKeyPair('ed25519')['private_key'],
        'team_id' => $this->team->id,
    ]);

    expect(privateKeyAuditEvents())->toBe(['ui.private_key.created']);
});

it('records one audit event with the real changed fields when a key is replaced', function () {
    Livewire::test(Show::class, ['private_key_uuid' => $this->privateKey->uuid])
        ->set('newPrivateKey', PrivateKey::generateNewKeyPair('ed25519')['private_key'])
        ->call('changePrivateKey')
        ->assertDispatched('success');

    $events = AuditEvent::query()->where('event', 'ui.private_key.updated')->get();
    expect($events)->toHaveCount(1)
        ->and($events->first()->metadata['changed_fields'])->toContain('private_key', 'fingerprint')
        ->and(json_encode($events->first()->toArray()))->not->toContain('PRIVATE KEY');
});

it('records no update audit event when nothing changed', function () {
    Livewire::test(Show::class, ['private_key_uuid' => $this->privateKey->uuid])
        ->call('changePrivateKey')
        ->assertDispatched('success');

    expect(privateKeyAuditEvents())->toBe([]);
});

it('keeps an empty description as null so a save without changes is not audited', function () {
    $this->privateKey->update(['description' => null]);
    AuditEvent::query()->delete();

    Livewire::test(Show::class, ['private_key_uuid' => $this->privateKey->uuid])
        ->set('description', '')
        ->call('changePrivateKey')
        ->assertDispatched('success');

    expect($this->privateKey->fresh()->description)->toBeNull()
        ->and(privateKeyAuditEvents())->toBe([]);
});

it('records one audit event when a key is deleted', function () {
    Livewire::test(Show::class, ['private_key_uuid' => $this->privateKey->uuid])
        ->call('delete')
        ->assertDispatched('privateKeyDeleted');

    expect(privateKeyAuditEvents())->toBe(['ui.private_key.deleted']);
});

it('audits a failed reveal and does not return the key', function () {
    Livewire::test(Show::class, ['private_key_uuid' => $this->privateKey->uuid])
        ->call('revealPrivateKey', 'wrong-password')
        ->assertReturned(null);

    expect(privateKeyAuditEvents())->toBe(['ui.private_key.reveal_failed']);
});

it('blocks reveal after too many attempts, also with the correct password', function () {
    $component = Livewire::test(Show::class, ['private_key_uuid' => $this->privateKey->uuid]);

    foreach (range(1, 5) as $attempt) {
        $component->call('revealPrivateKey', 'wrong-password')->assertReturned(null);
    }

    $component->call('revealPrivateKey', 'password')->assertReturned(null);
    expect(privateKeyAuditEvents())->not->toContain('ui.private_key.revealed');
});
