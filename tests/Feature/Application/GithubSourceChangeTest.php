<?php

use App\Livewire\Source\Github\Change;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create a team with owner
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    // Set current team
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => null,
        'public_ipv4' => null,
        'public_ipv6' => null,
    ]);
});

function validPrivateKey(): string
{
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($key, $privateKey);

    return $privateKey;
}

describe('GitHub Source Change Component', function () {
    test('all github app form controls declare explicit authorization', function () {
        $view = file_get_contents(resource_path('views/livewire/source/github/change.blade.php'));

        preg_match_all(
            '/<x-forms\.(button|input|select|checkbox)\b(?![^>]*\bcanGate=)[^>]*>/s',
            $view,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $missingAuthorization = collect($matches[0])
            ->map(fn (array $match): string => 'Line '.(substr_count(substr($view, 0, $match[1]), PHP_EOL) + 1).': '.trim(preg_replace('/\s+/', ' ', $match[0])))
            ->all();

        expect($missingAuthorization)->toBeEmpty();
    });

    test('can mount with newly created github app with null app_id', function () {
        // Create a GitHub app without app_id (simulating a newly created source)
        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            // app_id is intentionally not set (null in database)
        ]);

        // Test that the component can mount without errors
        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful()
            ->assertSet('appId', null)
            ->assertSet('installationId', null)
            ->assertSet('clientId', null)
            ->assertSet('clientSecret', null)
            ->assertSet('webhookSecret', null)
            ->assertSet('privateKeyId', null);
    });

    test('creates one-time states for manifest conversion and installation callbacks', function () {
        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
        ]);

        $component = Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful();

        $manifestState = $component->get('manifestState');
        $installationUrl = getInstallationPath($githubApp);
        parse_str(parse_url($installationUrl, PHP_URL_QUERY), $query);
        $installState = $query['state'] ?? null;

        expect($manifestState)->not->toBeEmpty()
            ->and($installState)->not->toBeEmpty()
            ->and($installState)->not->toBe($manifestState)
            ->and($installationUrl)->not->toContain($githubApp->uuid)
            ->and(Cache::get('github-app-setup-state:'.hash('sha256', $manifestState)))
            ->toMatchArray([
                'action' => 'manifest',
                'github_app_id' => $githubApp->id,
                'team_id' => $githubApp->team_id,
            ])
            ->and(Cache::get('github-app-setup-state:'.hash('sha256', $installState)))
            ->toMatchArray([
                'action' => 'install',
                'github_app_id' => $githubApp->id,
                'team_id' => $githubApp->team_id,
            ]);
    });

    test('installation path is generated from the provided github app instance', function () {
        $githubApp = new GithubApp;
        $githubApp->forceFill([
            'id' => 123,
            'name' => 'Provided GitHub App',
            'html_url' => 'https://github.example.com',
            'team_id' => 456,
        ]);

        $installationUrl = getInstallationPath($githubApp);
        parse_str(parse_url($installationUrl, PHP_URL_QUERY), $query);
        $installState = $query['state'] ?? null;

        expect($installationUrl)->toStartWith('https://github.example.com/github-apps/provided-git-hub-app/installations/new?')
            ->and($installState)->not->toBeEmpty()
            ->and(Cache::get('github-app-setup-state:'.hash('sha256', $installState)))
            ->toMatchArray([
                'action' => 'install',
                'github_app_id' => 123,
                'team_id' => 456,
            ]);
    });

    test('defaults webhook endpoint to app url when it is the first available endpoint', function () {
        config(['app.url' => 'http://localhost:8000']);

        InstanceSettings::findOrFail(0)->update([
            'fqdn' => null,
            'public_ipv4' => null,
            'public_ipv6' => null,
        ]);

        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
        ]);

        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful()
            ->assertSet('webhook_endpoint', 'http://localhost:8000');
    });

    test('custom webhook endpoint is selected explicitly with a checkbox', function () {
        config(['app.url' => 'http://localhost:8000']);

        InstanceSettings::findOrFail(0)->update([
            'fqdn' => 'http://staging.example.com',
            'public_ipv4' => '84.1.202.183',
            'public_ipv6' => null,
        ]);

        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
        ]);

        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful()
            ->assertSet('use_custom_webhook_endpoint', false)
            ->set('custom_webhook_endpoint', 'https://staging.example.com')
            ->set('use_custom_webhook_endpoint', true)
            ->assertSet('webhook_endpoint', 'http://staging.example.com')
            ->assertSet('custom_webhook_endpoint', 'https://staging.example.com')
            ->assertSet('use_custom_webhook_endpoint', true)
            ->assertSee('Use custom webhook endpoint')
            ->assertSee('Selected endpoint')
            ->assertSee('Custom endpoint')
            ->assertSee('createGithubApp(webhookEndpoint, useCustomWebhookEndpoint, customWebhookEndpoint');
    });

    test('can mount with fully configured github app', function () {
        $privateKey = PrivateKey::create([
            'name' => 'Test Key',
            'private_key' => validPrivateKey(),
            'team_id' => $this->team->id,
        ]);

        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'webhook_secret' => 'test-webhook-secret',
            'private_key_id' => $privateKey->id,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
        ]);

        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful()
            ->assertSet('appId', 12345)
            ->assertSet('installationId', 67890)
            ->assertSet('clientId', 'test-client-id')
            ->assertSet('clientSecret', 'test-client-secret')
            ->assertSet('webhookSecret', 'test-webhook-secret')
            ->assertSet('privateKeyId', $privateKey->id);
    });

    test('can update github app from null to valid values', function () {
        $privateKey = PrivateKey::create([
            'name' => 'Test Key',
            'private_key' => validPrivateKey(),
            'team_id' => $this->team->id,
        ]);

        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
        ]);

        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful()
            ->set('appId', 12345)
            ->set('installationId', 67890)
            ->set('clientId', 'new-client-id')
            ->set('clientSecret', 'new-client-secret')
            ->set('webhookSecret', 'new-webhook-secret')
            ->set('privateKeyId', $privateKey->id)
            ->call('submit')
            ->assertDispatched('success');

        // Verify the database was updated
        $githubApp->refresh();
        expect($githubApp->app_id)->toBe(12345);
        expect($githubApp->installation_id)->toBe(67890);
        expect($githubApp->client_id)->toBe('new-client-id');
        expect($githubApp->private_key_id)->toBe($privateKey->id);
    });

    test('validation allows nullable values for app configuration', function () {
        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
        ]);

        // Test that validation passes with null values
        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful()
            ->call('submit')
            ->assertHasNoErrors();
    });

    test('createGithubAppManually redirects to avoid morphing issues', function () {
        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
        ]);

        // Test that createGithubAppManually redirects instead of updating in place
        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful()
            ->call('createGithubAppManually')
            ->assertRedirect(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));

        // Verify the database was updated
        $githubApp->refresh();
        expect($githubApp->app_id)->toBe(1234567890);
        expect($githubApp->installation_id)->toBe(1234567890);
    });

    test('checkPermissions validates required fields', function () {
        // Create a GitHub app without app_id and private_key_id
        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
        ]);

        // Test that checkPermissions fails with appropriate error
        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful()
            ->call('checkPermissions')
            ->assertDispatched('error', function ($event, $message) {
                $message = is_array($message) ? implode(' ', $message) : $message;

                return str_contains($message, 'App ID') && str_contains($message, 'Private Key');
            });
    });

    test('checkPermissions validates private key exists', function () {
        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'app_id' => 12345,
            'private_key_id' => 99999, // Non-existent private key ID
            'team_id' => $this->team->id,
            'is_system_wide' => false,
        ]);

        // Test that checkPermissions fails when private key doesn't exist
        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful()
            ->call('checkPermissions')
            ->assertDispatched('error', function ($event, $message) {
                $message = is_array($message) ? implode(' ', $message) : $message;

                return str_contains($message, 'Private Key not found');
            });
    });

    test('checkPermissions syncs refetched permissions into input fields', function () {
        $privateKey = PrivateKey::create([
            'name' => 'Test Key',
            'private_key' => validPrivateKey(),
            'team_id' => $this->team->id,
        ]);

        $githubApp = GithubApp::create([
            'name' => 'Test GitHub App',
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'custom_user' => 'git',
            'custom_port' => 22,
            'app_id' => 12345,
            'installation_id' => 67890,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'webhook_secret' => 'test-webhook-secret',
            'private_key_id' => $privateKey->id,
            'team_id' => $this->team->id,
            'is_system_wide' => false,
            'contents' => null,
            'metadata' => null,
            'pull_requests' => null,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/zen' => Http::response('Keep it logically awesome.', 200, [
                'date' => now()->toRfc7231String(),
            ]),
            'https://api.github.com/app' => Http::response([
                'permissions' => [
                    'contents' => 'read',
                    'metadata' => 'read',
                    'pull_requests' => 'write',
                ],
            ]),
        ]);

        Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
            ->test(Change::class)
            ->assertSuccessful()
            ->assertSet('name', 'test-git-hub-app')
            ->assertSet('contents', null)
            ->assertSet('metadata', null)
            ->assertSet('pullRequests', null)
            ->call('checkPermissions')
            ->assertDispatched('success')
            ->assertSet('name', 'test-git-hub-app')
            ->assertSet('contents', 'read')
            ->assertSet('metadata', 'read')
            ->assertSet('pullRequests', 'write');

        $githubApp->refresh();

        expect($githubApp->contents)->toBe('read')
            ->and($githubApp->metadata)->toBe('read')
            ->and($githubApp->pull_requests)->toBe('write');
    });
});
