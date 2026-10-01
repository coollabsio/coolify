<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Livewire\Project\Shared\SecretManagerLinks;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Js;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->token = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'Doppler production',
        'token' => 'dp.st.token',
        'capabilities' => ['secrets'],
    ]);
});

test('selecting a token in the dropdown saves the source automatically', function () {
    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->set('integration_token_uuid', $this->token->uuid)
        ->assertDispatched('success');

    $this->assertDatabaseHas('secret_manager_links', [
        'resourceable_type' => $this->application->getMorphClass(),
        'resourceable_id' => $this->application->id,
        'integration_token_id' => $this->token->id,
    ]);
    $this->assertDatabaseHas('audit_events', [
        'team_id' => $this->team->id,
        'event' => 'ui.application.secret_manager.source_updated',
        'resource_uuid' => $this->application->uuid,
    ]);
});

test('service account settings are required and save automatically on blur', function () {
    $serviceAccountToken = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'Doppler service account',
        'token' => 'dp.sa.token',
        'capabilities' => ['secrets'],
    ]);

    $this->application->secretManagerLink()->create(['integration_token_id' => $serviceAccountToken->id]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->call('saveSettings')
        ->assertHasErrors(['settings.project', 'settings.config']);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->set('settings', ['project' => 'proj', 'config' => 'prd'])
        ->call('saveSettings')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->application->secretManagerLink()->firstOrFail()->settings)
        ->toBe(['project' => 'proj', 'config' => 'prd']);
});

test('doppler settings match the selected token type', function () {
    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->assertSee('Project and config are fixed by this service token.')
        ->assertDontSee('Project (required)');

    $serviceAccountToken = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'Doppler service account',
        'token' => 'dp.sa.token',
        'capabilities' => ['secrets'],
    ]);

    $this->application->secretManagerLink()->update([
        'integration_token_id' => $serviceAccountToken->id,
    ]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->assertSee('Project (required)')
        ->assertSee('Config (required)');
});

test('selecting another token replaces the source and clears provider settings without checking references', function () {
    $this->application->secretManagerLink()->create([
        'integration_token_id' => $this->token->id,
        'settings' => ['project' => 'proj'],
    ]);
    $this->application->environment_variables()->create(['key' => 'A', 'value' => '{{vault.A}}']);

    $otherToken = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'vault',
        'name' => 'Vault',
        'token' => 'hvs.token',
        'capabilities' => ['secrets'],
        'metadata' => ['base_url' => 'https://vault.internal:8200'],
    ]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->set('integration_token_uuid', $otherToken->uuid)
        ->assertDispatched('success')
        ->assertSet('settings', []);

    $this->assertDatabaseCount('secret_manager_links', 1);
    $this->assertDatabaseHas('secret_manager_links', [
        'integration_token_id' => $otherToken->id,
        'settings' => null,
    ]);

    Http::assertNothingSent();
});

test('browse keys shows key names only and search filters them', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'DB_PASSWORD' => 'super-secret-value',
            'API_KEY' => 'another-secret',
        ]),
    ]);

    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);

    $component = Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->call('loadKeys')
        ->assertSee('DB_PASSWORD')
        ->assertSee('API_KEY')
        ->assertSee('{{vault.DB_PASSWORD}}')
        ->assertSeeHtml('class="flex min-w-0 flex-col"')
        ->assertDontSee('{{ $key }}')
        ->assertDontSee('super-secret-value')
        ->assertDontSee('another-secret');

    expect($component->get('keys'))->toBe(['API_KEY', 'DB_PASSWORD']);

    $auditEvent = AuditEvent::query()->where('event', 'ui.application.secret_manager.keys_viewed')->sole();
    expect($auditEvent->metadata['key_count'])->toBe(2)
        ->and($auditEvent->metadata)->not->toHaveKey('keys');

    $component->set('search', 'db_pass')
        ->assertSee('DB_PASSWORD')
        ->assertDontSee('API_KEY');
});

test('browse keys marks names that are not valid variable keys without an add action', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'API_KEY' => 'a',
            'db-password' => 'b',
            "TEAM'S_KEY" => 'apostrophe-secret',
            '<b>HTML</b>' => 'html-secret',
        ]),
    ]);

    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->call('loadKeys')
        ->assertSeeHtml('wire:click="addReference('.Js::from('API_KEY').')"')
        ->assertSee('db-password')
        ->assertSee('Not a valid variable name')
        ->assertDontSeeHtml('addReference('.Js::from('db-password').')')
        ->assertDontSeeHtml('addReference('.Js::from("TEAM'S_KEY").')')
        ->assertSeeHtml('&lt;b&gt;HTML&lt;/b&gt;')
        ->assertDontSeeHtml('<b>HTML</b>');
});

test('add reference rejects a remote name that is not a valid variable key', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'db-password' => 'b',
        ]),
    ]);

    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->call('loadKeys')
        ->call('addReference', 'db-password')
        ->assertDispatched('error', 'db-password is not a valid variable name.')
        ->assertNotDispatched('refreshEnvs');

    expect($this->application->environment_variables()->where('key', 'db-password')->exists())->toBeFalse();
});

test('add reference creates a variable with a secret reference value', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'DB_PASSWORD' => 'super-secret-value',
        ]),
    ]);

    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->call('loadKeys')
        ->call('addReference', 'DB_PASSWORD')
        ->assertDispatchedTo(All::class, 'refreshEnvs')
        ->assertDispatched('success');

    $created = $this->application->environment_variables()->where('key', 'DB_PASSWORD')->firstOrFail();
    expect($created->value)->toBe('{{vault.DB_PASSWORD}}');

    $auditEvent = AuditEvent::query()->where('event', 'ui.application.secret_manager.reference_created')->sole();
    expect($auditEvent->metadata['secret_key'])->toBe('[REDACTED]');
});

test('import all creates references for missing keys and skips existing ones', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'EXISTING' => 'a',
            'NEW_KEY' => 'b',
        ]),
    ]);

    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);
    $this->application->environment_variables()->create(['key' => 'EXISTING', 'value' => 'local']);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->call('importAll')
        ->assertDispatchedTo(All::class, 'refreshEnvs')
        ->assertDispatched('success', 'Imported 1 keys as {{vault.KEY}} references.');

    expect($this->application->environment_variables()->where('key', 'NEW_KEY')->firstOrFail()->value)
        ->toBe('{{vault.NEW_KEY}}')
        ->and($this->application->environment_variables()->where('key', 'EXISTING')->firstOrFail()->value)
        ->toBe('local');

    $auditEvent = AuditEvent::query()->where('event', 'ui.application.secret_manager.references_imported')->sole();
    expect($auditEvent->metadata['key_count'])->toBe(1)
        ->and($auditEvent->metadata['secret_keys'])->toBe('[REDACTED]');
});

test('import all skips invalid remote names, imports the rest, and reports both', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'API_KEY' => 'a',
            'db-password' => 'b',
            'ZED' => 'c',
        ]),
    ]);

    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->call('importAll')
        ->assertDispatchedTo(All::class, 'refreshEnvs')
        ->assertDispatched('success', 'Imported 2 keys as {{vault.KEY}} references. Skipped 1 key that is not a valid variable name: db-password.')
        ->assertNotDispatched('error');

    expect($this->application->environment_variables()->whereIn('key', ['API_KEY', 'ZED'])->pluck('value', 'key')->sortKeys()->all())
        ->toBe(['API_KEY' => '{{vault.API_KEY}}', 'ZED' => '{{vault.ZED}}'])
        ->and($this->application->environment_variables()->where('key', 'db-password')->exists())->toBeFalse();

    $auditEvent = AuditEvent::query()->where('event', 'ui.application.secret_manager.references_imported')->sole();
    expect($auditEvent->metadata['key_count'])->toBe(2)
        ->and($auditEvent->metadata['skipped_key_count'])->toBe(1);
});

test('import all reports skipped names when no remote name is a valid variable key', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'db-password' => 'a',
            '<b>x</b>' => 'b',
        ]),
    ]);

    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->call('importAll')
        ->assertDispatched('success', 'No keys imported. Skipped 2 keys that are not valid variable names: &lt;b&gt;x&lt;/b&gt;, db-password.');

    expect($this->application->environment_variables()->whereIn('key', ['db-password', '<b>x</b>'])->exists())->toBeFalse();
});

test('the source can be removed', function () {
    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->call('removeSource')
        ->assertDispatched('success');

    $this->assertDatabaseCount('secret_manager_links', 0);
    $this->assertDatabaseHas('audit_events', [
        'team_id' => $this->team->id,
        'event' => 'ui.application.secret_manager.source_removed',
        'resource_uuid' => $this->application->uuid,
    ]);
});

test('only tokens of the resource team can be linked, also after a team switch', function () {
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->user->id, ['role' => 'member']);
    $otherToken = IntegrationToken::query()->create([
        'team_id' => $otherTeam->id,
        'provider' => 'doppler',
        'name' => 'Other team Doppler',
        'token' => 'dp.st.other',
        'capabilities' => ['secrets'],
    ]);

    $component = Livewire::test(SecretManagerLinks::class, ['resource' => $this->application]);
    // The user switches to the other team in another browser tab; the next save reloads the token list.
    session(['currentTeam' => $otherTeam]);
    $component->set('integration_token_uuid', $this->token->uuid)
        ->set('integration_token_uuid', $otherToken->uuid);

    expect($component->get('availableTokens')->pluck('id')->all())->toBe([$this->token->id]);
    $this->assertDatabaseMissing('secret_manager_links', ['integration_token_id' => $otherToken->id]);
    $this->assertDatabaseHas('secret_manager_links', [
        'resourceable_id' => $this->application->id,
        'integration_token_id' => $this->token->id,
    ]);
});

test('members without update permission cannot save a source', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(SecretManagerLinks::class, ['resource' => $this->application])
        ->set('integration_token_uuid', $this->token->uuid)
        ->assertDispatched('error', 'You need at least admin or owner permissions to update this application.');

    $this->assertDatabaseCount('secret_manager_links', 0);
});

test('the edit modal value autocomplete offers the vault scope with lazy key fetch', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'DB_PASSWORD' => 'super-secret-value',
        ]),
    ]);

    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);
    $env = $this->application->environment_variables()->create(['key' => 'MY_VAR', 'value' => 'plain']);

    $component = Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('loadValues')
        ->assertSeeHtml('hasVaultSource: true');

    expect($component->instance()->fetchSecretManagerKeys())->toBe(['DB_PASSWORD']);
});

test('the edit modal value autocomplete reports secret provider failures', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([], 503),
    ]);

    $this->application->secretManagerLink()->create(['integration_token_id' => $this->token->id]);
    $env = $this->application->environment_variables()->create(['key' => 'MY_VAR', 'value' => 'plain']);
    $component = Livewire::test(Show::class, ['env' => $env, 'type' => 'application']);

    expect(fn () => $component->instance()->fetchSecretManagerKeys())
        ->toThrow(RuntimeException::class, 'Unable to fetch secret manager keys.');
});

test('the edit modal value autocomplete has no vault scope without a source', function () {
    $env = $this->application->environment_variables()->create(['key' => 'MY_VAR', 'value' => 'plain']);

    $component = Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('loadValues')
        ->assertSeeHtml('hasVaultSource: false');

    expect($component->instance()->fetchSecretManagerKeys())->toBe([]);
});
