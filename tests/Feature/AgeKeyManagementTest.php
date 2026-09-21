<?php

use App\Livewire\Security\AgeKey\Create as AgeKeyCreate;
use App\Livewire\Security\AgeKey\Index as AgeKeyIndex;
use App\Livewire\Security\AgeKey\Show as AgeKeyShow;
use App\Models\AgeKey;
use App\Models\InstanceSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const AGE_MGMT_TEST_PUBLIC_KEY = 'age1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';
const AGE_MGMT_TEST_PRIVATE_KEY = 'AGE-SECRET-KEY-1QYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQYQSZQGPQ2XQ9VA';

beforeEach(function () {
    if (InstanceSettings::find(0) === null) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('age key index route renders and lists team keys', function () {
    $key = AgeKey::create([
        'name' => 'My key',
        'public_key' => AGE_MGMT_TEST_PUBLIC_KEY,
        'team_id' => $this->team->id,
    ]);

    $this->get(route('security.age-key.index'))
        ->assertOk()
        ->assertSee('My key')
        ->assertSee('Age keys');
});

test('age key can be added by pasting an existing public key', function () {
    Livewire::test(AgeKeyCreate::class)
        ->set('name', 'Pasted key')
        ->set('description', 'From CI')
        ->set('publicKey', AGE_MGMT_TEST_PUBLIC_KEY)
        ->call('createAgeKey');

    $key = AgeKey::where('name', 'Pasted key')->first();
    expect($key)->not->toBeNull();
    expect($key->public_key)->toBe(AGE_MGMT_TEST_PUBLIC_KEY);
    expect($key->team_id)->toBe($this->team->id);
});

test('age key creation rejects an invalid public key', function () {
    Livewire::test(AgeKeyCreate::class)
        ->set('name', 'Bad key')
        ->set('publicKey', 'not-a-key')
        ->call('createAgeKey')
        ->assertHasErrors();

    expect(AgeKey::where('name', 'Bad key')->exists())->toBeFalse();
});

test('generating an age key from the index shows the private key once and does not persist it', function () {
    Process::fake([
        'age-keygen' => Process::result(
            output: "# public key: ".AGE_MGMT_TEST_PUBLIC_KEY."\n".AGE_MGMT_TEST_PRIVATE_KEY."\n"
        ),
    ]);

    $component = Livewire::test(AgeKeyIndex::class)
        ->call('generateAgeKey')
        ->assertSet('generatedPrivateKey', AGE_MGMT_TEST_PRIVATE_KEY)
        ->assertSet('generatedPublicKey', AGE_MGMT_TEST_PUBLIC_KEY);

    expect(AgeKey::count())->toBe(0);

    $component->call('confirmGeneratedAgeKeySaved')
        ->assertSet('generatedPrivateKey', null);

    $key = AgeKey::first();
    expect($key)->not->toBeNull();
    expect($key->public_key)->toBe(AGE_MGMT_TEST_PUBLIC_KEY);
});

test('discarding a generated age key does not persist anything', function () {
    Process::fake([
        'age-keygen' => Process::result(
            output: "# public key: ".AGE_MGMT_TEST_PUBLIC_KEY."\n".AGE_MGMT_TEST_PRIVATE_KEY."\n"
        ),
    ]);

    Livewire::test(AgeKeyIndex::class)
        ->call('generateAgeKey')
        ->call('discardGeneratedAgeKey')
        ->assertSet('generatedPrivateKey', null)
        ->assertSet('generatedPublicKey', null);

    expect(AgeKey::count())->toBe(0);
});

test('age key show page blocks deletion while a backup schedule references it', function () {
    $key = AgeKey::create([
        'name' => 'In use key',
        'public_key' => AGE_MGMT_TEST_PUBLIC_KEY,
        'team_id' => $this->team->id,
    ]);

    ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'encryption_enabled' => true,
        'age_key_id' => $key->id,
        'database_type' => 'App\Models\StandalonePostgresql',
        'database_id' => 1,
        'team_id' => $this->team->id,
    ]);

    Livewire::test(AgeKeyShow::class, ['age_key_uuid' => $key->uuid])
        ->assertSet('isInUse', true)
        ->call('delete')
        ->assertDispatched('error');

    expect(AgeKey::find($key->id))->not->toBeNull();
});

test('age key show page allows deletion when unused', function () {
    $key = AgeKey::create([
        'name' => 'Unused key',
        'public_key' => AGE_MGMT_TEST_PUBLIC_KEY,
        'team_id' => $this->team->id,
    ]);

    Livewire::test(AgeKeyShow::class, ['age_key_uuid' => $key->uuid])
        ->assertSet('isInUse', false)
        ->call('delete');

    expect(AgeKey::find($key->id))->toBeNull();
});

test('a user from another team cannot view an age key via the show route', function () {
    $key = AgeKey::create([
        'name' => 'Other team key',
        'public_key' => AGE_MGMT_TEST_PUBLIC_KEY,
        'team_id' => $this->team->id,
    ]);

    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);
    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    // The team-scoped query in mount() excludes other teams' keys entirely, so this
    // 404s rather than 403s — matching PrivateKey\Show's identical team-scoping pattern.
    $this->get(route('security.age-key.show', ['age_key_uuid' => $key->uuid]))
        ->assertNotFound();
});

test('backup edit encryption section links to the dedicated age key management page', function () {
    $blade = file_get_contents(resource_path('views/livewire/project/database/backup-edit/s3.blade.php'));

    expect($blade)
        ->toContain("route('security.age-key.index')")
        ->not->toContain('generateAgeKey')
        ->not->toContain('addExistingAgeKey');
});
