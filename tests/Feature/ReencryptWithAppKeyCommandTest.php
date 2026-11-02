<?php

use App\Actions\Server\StartSentinel;
use App\Console\Commands\ReencryptWithAppKey;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Finder\Finder;

uses(RefreshDatabase::class);

function useAppKeys(string $key, array $previousKeys = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previousKeys]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function newAppKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

beforeEach(function () {
    Queue::fake();
    $this->oldKey = newAppKey();
    $this->newKey = newAppKey();
    useAppKeys($this->oldKey);

    InstanceSettings::forceCreate(['id' => 0, 'smtp_password' => 'instance-smtp-password']);
    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id]);
    $this->server->settings->forceFill(['is_sentinel_enabled' => true])->saveQuietly();
    $this->environmentVariable = EnvironmentVariable::create([
        'key' => 'DATABASE_URL',
        'value' => 'postgres://user:secret@db:5432/app',
        'resourceable_type' => Application::class,
        'resourceable_id' => Application::factory()->create()->id,
    ]);
    $this->gitlabApp = GitlabApp::create([
        'name' => 'GitLab',
        'api_url' => 'https://gitlab.example.com/api/v4',
        'html_url' => 'https://gitlab.example.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'webhook_token' => 'secret-webhook-token',
        'team_id' => $this->team->id,
    ]);
    $this->oldSentinelToken = $this->server->settings->sentinel_token;
    $this->privateKeyValue = $this->privateKey->private_key;
});

it('re-encrypts stored secrets so they decrypt without the previous key', function () {
    useAppKeys($this->newKey, [$this->oldKey]);

    $this->artisan('app-key:reencrypt --force')
        ->expectsOutputToContain('Regenerated 1 Sentinel tokens.')
        ->assertSuccessful();
    StartSentinel::assertPushed();

    useAppKeys($this->newKey);

    expect(InstanceSettings::query()->find(0)->smtp_password)->toBe('instance-smtp-password')
        ->and($this->privateKey->fresh()->private_key)->toBe($this->privateKeyValue)
        ->and($this->environmentVariable->fresh()->value)->toBe('postgres://user:secret@db:5432/app')
        ->and($this->gitlabApp->fresh()->webhook_token)->toBe('secret-webhook-token');

    $sentinelToken = $this->server->settings->fresh()->sentinel_token;
    expect($sentinelToken)->not->toBe($this->oldSentinelToken)
        ->and(json_decode(decrypt($sentinelToken), true))->toBe(['server_uuid' => $this->server->uuid]);
});

it('changes nothing when every secret already uses the current key', function () {
    useAppKeys($this->newKey, [$this->oldKey]);
    $this->artisan('app-key:reencrypt --force')->assertSuccessful();
    $rawPrivateKey = DB::table('private_keys')->where('id', $this->privateKey->id)->value('private_key');

    $this->artisan('app-key:reencrypt --force')
        ->expectsOutputToContain('Re-encrypted 0 values.')
        ->expectsOutputToContain('Regenerated 0 Sentinel tokens.')
        ->assertSuccessful();

    expect(DB::table('private_keys')->where('id', $this->privateKey->id)->value('private_key'))->toBe($rawPrivateKey);
});

it('reports old-key values in a dry run without changing them', function () {
    $rawPrivateKey = DB::table('private_keys')->where('id', $this->privateKey->id)->value('private_key');
    useAppKeys($this->newKey, [$this->oldKey]);

    $this->artisan('app-key:reencrypt --dry-run')
        ->expectsOutputToContain('values and 1 Sentinel tokens use an old key. Nothing was changed.')
        ->assertSuccessful();

    expect(DB::table('private_keys')->where('id', $this->privateKey->id)->value('private_key'))->toBe($rawPrivateKey)
        ->and($this->server->settings->fresh()->sentinel_token)->toBe($this->oldSentinelToken);
    StartSentinel::assertNotPushed();
});

it('keeps legacy plaintext values as they are', function () {
    DB::table('gitlab_apps')->where('id', $this->gitlabApp->id)->update(['webhook_token' => 'legacy-plaintext-token']);
    useAppKeys($this->newKey, [$this->oldKey]);

    $this->artisan('app-key:reencrypt --force')->assertSuccessful();

    expect(DB::table('gitlab_apps')->where('id', $this->gitlabApp->id)->value('webhook_token'))->toBe('legacy-plaintext-token');
});

it('fails and keeps values that no configured key can decrypt', function () {
    $rawPrivateKey = DB::table('private_keys')->where('id', $this->privateKey->id)->value('private_key');
    useAppKeys($this->newKey);

    $this->artisan('app-key:reencrypt --force')
        ->expectsOutputToContain('cannot be decrypted with APP_KEY or APP_PREVIOUS_KEYS')
        ->assertFailed();

    expect(DB::table('private_keys')->where('id', $this->privateKey->id)->value('private_key'))->toBe($rawPrivateKey);
});

it('knows every model that encrypts data without an encrypted cast', function () {
    // Reviewed: EnvironmentVariable encrypts its "value" column, which also has an encrypted cast.
    // ServerSetting encrypts the Sentinel token, which the command regenerates.
    $reviewed = [EnvironmentVariable::class, ServerSetting::class];

    $manualEncryption = collect(Finder::create()->files()->in(app_path('Models'))->name('*.php'))
        ->filter(fn ($file) => preg_match('/\bCrypt::|(?<![\w>:$])(en|de)crypt\(/', $file->getContents()) === 1)
        ->map(fn ($file) => 'App\\Models\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
        ->values()
        ->all();

    expect(array_diff($manualEncryption, [...array_keys(ReencryptWithAppKey::ACCESSOR_ENCRYPTED_COLUMNS), ...$reviewed]))
        ->toBe([], 'Add the encrypted columns of these models to ReencryptWithAppKey::ACCESSOR_ENCRYPTED_COLUMNS.');
});
