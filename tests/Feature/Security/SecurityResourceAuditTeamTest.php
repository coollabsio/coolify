<?php

use App\Livewire\Security\CloudInitScript\Show as CloudInitScriptShow;
use App\Livewire\Security\CloudProviderToken\Show as CloudProviderTokenShow;
use App\Livewire\Security\IntegrationTokenEditor;
use App\Models\AuditEvent;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }
    Once::flush();

    $this->resourceTeam = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->resourceTeam->members()->attach($this->user->id, ['role' => 'admin']);
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->resourceTeam]);
    $this->actingAs($this->user);
});

function switchSecurityAuditSessionTeam(Team $team): void
{
    session(['currentTeam' => $team]);
    Cache::flush();
    Once::flush();
}

function expectSecurityAuditEventInResourceTeam(string $event, Team $resourceTeam, Team $otherTeam, array $secrets): void
{
    $auditEvent = AuditEvent::query()->where('event', $event)->sole();

    expect($auditEvent->team_id)->toBe($resourceTeam->id)
        ->and(AuditEvent::query()->where('team_id', $otherTeam->id)->exists())->toBeFalse();

    $stored = json_encode($auditEvent->toArray());
    foreach ($secrets as $secret) {
        expect($stored)->not->toContain($secret);
    }
}

test('integration token updates are audited in the token team after a session team switch', function () {
    $token = IntegrationToken::query()->create([
        'team_id' => $this->resourceTeam->id,
        'provider' => 'doppler',
        'name' => 'Production secrets',
        'token' => 'dp.st.super-secret',
        'capabilities' => ['secrets'],
    ]);

    $component = Livewire::test(IntegrationTokenEditor::class, ['integration_token_uuid' => $token->uuid]);
    switchSecurityAuditSessionTeam($this->otherTeam);

    $component->set('name', 'Renamed secrets')->call('save')->assertHasNoErrors();

    expect($token->fresh()->name)->toBe('Renamed secrets');
    expectSecurityAuditEventInResourceTeam('ui.integration_token.updated', $this->resourceTeam, $this->otherTeam, ['dp.st.super-secret']);
});

test('integration token base url changes are audited in the token team without url credentials', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $token = IntegrationToken::query()->create([
        'team_id' => $this->resourceTeam->id,
        'provider' => 'vault',
        'name' => 'Production Vault',
        'token' => 'hvs.stored-secret',
        'capabilities' => ['secrets'],
        'metadata' => ['base_url' => 'https://old-user:old-pass@example.com:8200'],
    ]);

    $component = Livewire::test(IntegrationTokenEditor::class, ['integration_token_uuid' => $token->uuid]);
    switchSecurityAuditSessionTeam($this->otherTeam);

    $component->set('metadata.base_url', 'https://new-user:new-pass@example.net:8200')
        ->set('newToken', 'hvs.new-secret')
        ->call('save')
        ->assertHasNoErrors();

    expect($token->fresh()->token)->toBe('hvs.new-secret');
    expectSecurityAuditEventInResourceTeam('ui.integration_token.base_url_changed', $this->resourceTeam, $this->otherTeam, [
        'hvs.stored-secret', 'hvs.new-secret', 'old-pass', 'new-pass',
    ]);
    $auditEvent = AuditEvent::query()->where('event', 'ui.integration_token.base_url_changed')->sole();
    expect(data_get($auditEvent->metadata, 'previous_base_url'))->toBe('https://example.com:8200')
        ->and(data_get($auditEvent->metadata, 'base_url'))->toBe('https://example.net:8200');
});

test('integration token deletes are audited in the token team after a session team switch', function () {
    $token = IntegrationToken::query()->create([
        'team_id' => $this->resourceTeam->id,
        'provider' => 'doppler',
        'name' => 'Production secrets',
        'token' => 'dp.st.super-secret',
        'capabilities' => ['secrets'],
    ]);

    $component = Livewire::test(IntegrationTokenEditor::class, ['integration_token_uuid' => $token->uuid]);
    switchSecurityAuditSessionTeam($this->otherTeam);

    $component->call('delete');

    expect(IntegrationToken::query()->whereKey($token->id)->exists())->toBeFalse();
    expectSecurityAuditEventInResourceTeam('ui.integration_token.deleted', $this->resourceTeam, $this->otherTeam, ['dp.st.super-secret']);
});

test('cloud provider token changes are audited in the token team after a session team switch', function (string $action, string $event) {
    Http::fake(['*' => Http::response([])]);
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->resourceTeam->id,
        'token' => 'hcloud-super-secret',
        'name' => 'Production Hetzner',
    ]);

    $component = Livewire::test(CloudProviderTokenShow::class, ['cloud_token_uuid' => $token->uuid]);
    switchSecurityAuditSessionTeam($this->otherTeam);

    $component->set('name', 'Renamed Hetzner')->call($action)->assertHasNoErrors();

    expectSecurityAuditEventInResourceTeam($event, $this->resourceTeam, $this->otherTeam, ['hcloud-super-secret']);
})->with([
    'save' => ['save', 'ui.cloud_token.updated'],
    'validate' => ['validateToken', 'ui.cloud_token.validated'],
    'delete' => ['delete', 'ui.cloud_token.deleted'],
]);

test('cloud-init script changes are audited in the script team after a session team switch', function (string $action, string $event) {
    $script = CloudInitScript::query()->create([
        'team_id' => $this->resourceTeam->id,
        'name' => 'Bootstrap',
        'script' => "#cloud-config\nruncmd:\n  - echo cloud-init-super-secret\n",
    ]);

    $component = Livewire::test(CloudInitScriptShow::class, ['cloud_init_script_uuid' => $script->uuid]);
    switchSecurityAuditSessionTeam($this->otherTeam);

    $component->set('script', "#cloud-config\nruncmd:\n  - echo cloud-init-new-secret\n")->call($action)->assertHasNoErrors();

    expectSecurityAuditEventInResourceTeam($event, $this->resourceTeam, $this->otherTeam, ['cloud-init-super-secret', 'cloud-init-new-secret']);
})->with([
    'save' => ['save', 'ui.cloud_init_script.updated'],
    'delete' => ['delete', 'ui.cloud_init_script.deleted'],
]);
