<?php

use App\Livewire\Security\IntegrationTokenEditor;
use App\Livewire\Security\IntegrationTokenForm;
use App\Livewire\Security\IntegrationTokens;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);
});

test('a cloudflare dns token is validated with read only requests before it is saved', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => true,
            'result' => ['status' => 'active'],
        ]),
        'https://api.cloudflare.com/client/v4/zones?per_page=1' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-id']],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-id/dns_records?per_page=1' => Http::response([
            'success' => true,
            'result' => [],
        ]),
    ]);

    Livewire::test(IntegrationTokenForm::class, ['modal_mode' => true])
        ->set('provider', 'cloudflare')
        ->set('name', 'Production DNS')
        ->set('token', 'cloudflare-token')
        ->set('capabilities', ['dns'])
        ->call('addToken')
        ->assertHasNoErrors()
        ->assertDispatched('close-modal');

    $this->assertDatabaseHas('integration_tokens', [
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'Production DNS',
    ]);

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-id/dns_records?per_page=1');
});

test('a cloudflare token is not saved when scope validation fails', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => true,
            'result' => ['status' => 'active'],
        ]),
        'https://api.cloudflare.com/client/v4/zones?per_page=1' => Http::response([
            'success' => false,
            'errors' => [['message' => 'Authentication error']],
        ], 403),
    ]);

    Livewire::test(IntegrationTokenForm::class)
        ->set('name', 'Invalid DNS token')
        ->set('token', 'cloudflare-token')
        ->set('capabilities', ['dns'])
        ->call('addToken')
        ->assertDispatched('error');

    $this->assertDatabaseCount('integration_tokens', 0);
});

test('at least one capability is required when adding a cloudflare token', function () {
    Livewire::test(IntegrationTokenForm::class)
        ->set('name', 'Account token')
        ->set('token', 'cloudflare-token')
        ->set('capabilities', [])
        ->call('addToken')
        ->assertHasErrors(['capabilities' => 'required']);

    $this->assertDatabaseCount('integration_tokens', 0);
    Http::assertNothingSent();
});

test('provider validation uses the provider names declared by the model', function () {
    $component = file_get_contents(app_path('Livewire/Security/IntegrationTokenForm.php'));

    expect($component)
        ->toContain("implode(',', array_keys(IntegrationToken::PROVIDER_NAMES))")
        ->not->toContain('in:cloudflare,doppler,infisical,vault');
});

test('integration tokens page lists saved provider and capabilities', function () {
    IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'Production DNS',
        'token' => 'secret',
        'capabilities' => ['dns'],
    ]);

    Livewire::test(IntegrationTokens::class)
        ->assertSee('Production DNS')
        ->assertSee('Cloudflare')
        ->assertSee('DNS');
});

test('cloudflare dns scope guidance and token creation link are shown', function () {
    Livewire::test(IntegrationTokenForm::class)
        ->set('capabilities', ['dns'])
        ->assertSee('Zone - DNS - Edit')
        ->assertSee('Zone - Zone - Read')
        ->assertSeeHtml('https://dash.cloudflare.com/profile/api-tokens?permissionGroupKeys=%5B%7B%22key%22%3A%22dns%22%2C%22type%22%3A%22edit%22%7D%5D&amp;accountId=%2A&amp;zoneId=all&amp;name=Coolify%20DNS%20Management');

    expect(file_get_contents(resource_path('views/livewire/security/integration-token-form.blade.php')))
        ->toContain('permissionGroupKeys=%5B%7B%22key%22%3A%22dns%22%2C%22type%22%3A%22edit%22%7D%5D');
});

test('capability selection uses the shared checkbox component', function () {
    $view = file_get_contents(resource_path('views/livewire/security/integration-token-form.blade.php'));

    expect($view)
        ->toContain('<x-forms.checkbox')
        ->toContain('class="mt-3 rounded-lg border')
        ->not->toContain('<input type="checkbox"');
});

test('submit button uses the shared highlighted loading state', function () {
    $view = file_get_contents(resource_path('views/livewire/security/integration-token-form.blade.php'));

    expect($view)
        ->toContain('wire:target="addToken" isHighlighted')
        ->not->toContain('class="button-highlighted"');
});

test('saved integration token rows render modal editors with a gear button', function () {
    IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'Production DNS',
        'token' => 'original-token',
        'capabilities' => ['dns'],
    ]);

    Livewire::test(IntegrationTokens::class)
        ->assertSee('Edit Integration Token')
        ->assertSee('Production DNS')
        ->assertSeeHtml(':aria-label="`Edit ${tokenName}`"');
});

test('an integration token can be rotated after validating its capabilities', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => true,
            'result' => ['status' => 'active'],
        ]),
        'https://api.cloudflare.com/client/v4/zones?per_page=1' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-id']],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-id/dns_records?per_page=1' => Http::response([
            'success' => true,
            'result' => [],
        ]),
    ]);

    $savedToken = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'Production DNS',
        'token' => 'original-token',
        'capabilities' => ['dns'],
    ]);

    Livewire::test(IntegrationTokenEditor::class, ['integration_token_uuid' => $savedToken->uuid])
        ->set('name', 'Rotated DNS')
        ->set('newToken', 'rotated-token')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $savedToken->refresh();

    expect($savedToken->name)->toBe('Rotated DNS')
        ->and($savedToken->token)->toBe('rotated-token');
});

test('leaving the token field blank keeps the existing integration token', function () {
    Http::fake();

    $savedToken = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'Production DNS',
        'token' => 'original-token',
        'capabilities' => ['dns'],
    ]);

    Livewire::test(IntegrationTokenEditor::class, ['integration_token_uuid' => $savedToken->uuid])
        ->set('name', 'Renamed DNS')
        ->set('newToken', '')
        ->call('save')
        ->assertHasNoErrors();

    $savedToken->refresh();

    expect($savedToken->name)->toBe('Renamed DNS')
        ->and($savedToken->token)->toBe('original-token');

    Http::assertNothingSent();
});

test('an invalid replacement does not rotate the integration token', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => false,
        ], 403),
    ]);

    $savedToken = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'Production DNS',
        'token' => 'original-token',
        'capabilities' => ['dns'],
    ]);

    Livewire::test(IntegrationTokenEditor::class, ['integration_token_uuid' => $savedToken->uuid])
        ->set('newToken', 'invalid-token')
        ->call('save')
        ->assertDispatched('error');

    expect($savedToken->fresh()->token)->toBe('original-token');
});

test('editor updates its row without rerendering the teleported parent modal', function () {
    $component = file_get_contents(app_path('Livewire/Security/IntegrationTokenEditor.php'));

    expect($component)
        ->toContain("'integration-token-updated'")
        ->toContain("'integration-token-deleted'")
        ->not->toContain('integrationTokenChanged');
});
