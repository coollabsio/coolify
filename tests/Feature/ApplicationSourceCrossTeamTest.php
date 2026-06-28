<?php

use App\Livewire\Project\Application\Source;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

/**
 * Create a PrivateKey without firing model events. The PrivateKey `saving`
 * hook validates/fingerprints real key material and the `saved` hook writes
 * to the filesystem — neither is wanted in a unit test. Skipping events also
 * skips BaseModel's uuid generation, so the uuid is set explicitly here (it
 * is not in $fillable, so it cannot go through mass assignment).
 */
function makePrivateKey(string $name, string $material, string $fingerprint, int $teamId): PrivateKey
{
    return PrivateKey::withoutEvents(function () use ($name, $material, $fingerprint, $teamId) {
        $key = new PrivateKey([
            'name' => $name,
            'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\n{$material}\n-----END OPENSSH PRIVATE KEY-----",
            'fingerprint' => $fingerprint,
            'team_id' => $teamId,
        ]);
        $key->uuid = (string) new Cuid2;
        $key->save();

        return $key;
    });
}

beforeEach(function () {
    // handleError() turns a ModelNotFoundException into abort(404); rendering the 404
    // page reads InstanceSettings::get(), which findOrFail(0)s. Seed the singleton row.
    // `id` is not in $fillable, so it must be set outside of mass assignment.
    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    // Team A — the attacker
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->teamA->members()->attach($this->userA->id, ['role' => 'owner']);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);
    $this->applicationA = Application::factory()->create([
        'environment_id' => $this->environmentA->id,
        'private_key_id' => null,
        'source_id' => null,
        'source_type' => null,
    ]);

    // Team B — the victim (holds the secrets we are trying to steal)
    $this->teamB = Team::factory()->create();

    $this->victimPrivateKey = makePrivateKey('victim-ssh-key', 'VICTIM_KEY_MATERIAL', 'victim-fingerprint', $this->teamB->id);

    $this->victimGithubApp = GithubApp::create([
        'name' => 'victim-github-app',
        'team_id' => $this->teamB->id,
        'private_key_id' => $this->victimPrivateKey->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
    ]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('setPrivateKey rejects a PrivateKey owned by another team (GHSA-xrvp-4pp4-8rrw)', function () {
    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('setPrivateKey', $this->victimPrivateKey->id);

    $this->applicationA->refresh();
    expect($this->applicationA->private_key_id)->not->toBe($this->victimPrivateKey->id);
    expect($this->applicationA->private_key_id)->toBeNull();
});

test('setPrivateKey accepts a PrivateKey owned by the current team', function () {
    $ownKey = makePrivateKey('own-ssh-key', 'OWN_KEY_MATERIAL', 'own-fingerprint', $this->teamA->id);

    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('setPrivateKey', $ownKey->id);

    $this->applicationA->refresh();
    expect($this->applicationA->private_key_id)->toBe($ownKey->id);
});

test('changeSource rejects a GithubApp owned by another team (GHSA-xrvp-4pp4-8rrw)', function () {
    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('changeSource', $this->victimGithubApp->id, GithubApp::class);

    $this->applicationA->refresh();
    expect($this->applicationA->source_id)->not->toBe($this->victimGithubApp->id);
    expect($this->applicationA->source_type)->not->toBe(GithubApp::class);
});

test('changeSource rejects an arbitrary class as source_type', function () {
    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->call('changeSource', $this->victimGithubApp->id, Server::class);

    $this->applicationA->refresh();
    expect($this->applicationA->source_type)->not->toBe(Server::class);
});

test('privateKeyId is locked so submit() cannot persist a client-supplied foreign id', function () {
    // Without #[Locked], an attacker could POST {"updates": {"privateKeyId": <foreign_id>},
    // "calls": [{"method": "submit"}]} and have syncData(true) write the foreign id through
    // Application::update(['private_key_id' => $this->privateKeyId]) — bypassing setPrivateKey()
    // and its team-scoped lookup entirely. Locking the property closes that path at the wire layer.
    Livewire::test(Source::class, ['application' => $this->applicationA])
        ->set('privateKeyId', $this->victimPrivateKey->id);
})->throws(CannotUpdateLockedPropertyException::class);
