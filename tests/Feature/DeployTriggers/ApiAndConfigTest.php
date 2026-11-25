<?php

use App\Models\EnvironmentConfigLink;
use App\Models\InstanceSettings;
use App\Services\ProjectConfig\AdapterRegistry;
use App\Services\ProjectConfig\ApplyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    $this->fixture = deployTriggerFixture();
    InstanceSettings::get()->update(['is_api_enabled' => true]);
    session(['currentTeam' => $this->fixture['team']]);
    $this->application = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/web']);
    $this->application->forceFill([
        'trigger_base_commit' => str_repeat('a', 40),
        'trigger_set' => [
            'display' => ['apps/web/**'], 'limits' => ['build_context'], 'resolvers' => ['pnpm'], 'anchor' => ['package' => '@acme/web'],
            'widened' => [], 'evaluated_commit' => str_repeat('a', 40), 'compiled' => ['version' => 1, 'all_of' => []], 'config_fingerprint' => 'x',
        ],
    ])->save();
});

function triggerApiToken(array $fixture, array $abilities): string
{
    return $fixture['user']->createToken('deploy-triggers-'.implode('-', $abilities), $abilities)->plainTextToken;
}

it('updates the smart triggers setting through the api', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.triggerApiToken($this->fixture, ['write'])])
        ->patchJson("/api/v1/applications/{$this->application->uuid}", ['is_smart_triggers_enabled' => false])
        ->assertOk();

    expect($this->application->settings->refresh()->is_smart_triggers_enabled)->toBeFalse();
});

it('rejects the setting for a read-only token', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.triggerApiToken($this->fixture, ['read'])])
        ->patchJson("/api/v1/applications/{$this->application->uuid}", ['is_smart_triggers_enabled' => false])
        ->assertForbidden();

    expect($this->application->settings->refresh()->is_smart_triggers_enabled)->toBeTrue();
});

it('returns the trigger paths without the compiled set or the scan', function () {
    $response = $this->withHeaders(['Authorization' => 'Bearer '.triggerApiToken($this->fixture, ['read'])])
        ->getJson("/api/v1/applications/{$this->application->uuid}")
        ->assertOk();

    expect($response->json('trigger_set'))->toBe([
        'display' => ['apps/web/**'], 'limits' => ['build_context'], 'resolvers' => ['pnpm'], 'anchor' => ['package' => '@acme/web'],
        'widened' => [], 'evaluated_commit' => str_repeat('a', 40),
    ])->and($response->json('trigger_base_commit'))->toBe(str_repeat('a', 40))
        ->and($response->json('settings.is_smart_triggers_enabled'))->toBeTrue();
});

it('reads and canonicalizes the setting in coolify.json', function () {
    $link = EnvironmentConfigLink::factory()->make(['environment_id' => $this->fixture['environment']->id]);
    $link->setRelation('environment', $this->fixture['environment']);
    $adapter = app(AdapterRegistry::class)->for('application');
    $this->application->settings->update(['is_smart_triggers_enabled' => false]);

    $read = $adapter->read($this->application->fresh(), ApplyContext::for($link));

    expect($read['settings'])->toMatchArray(['is_smart_triggers_enabled' => false])
        ->and(json_encode($read))->not->toContain('trigger_base_commit')
        ->and(json_encode($read))->not->toContain('reference_paths');
});
