<?php

use App\Livewire\Source\Github\Change;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
});

describe('GitHub Source Change Component', function () {
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

    test('can mount with fully configured github app', function () {
        $privateKey = PrivateKey::create([
            'name' => 'Test Key',
            'private_key' => 'test-private-key-content',
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
            'private_key' => 'test-private-key-content',
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
        expect($githubApp->app_id)->toBe('1234567890');
        expect($githubApp->installation_id)->toBe('1234567890');
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
                return str_contains($message, 'Private Key not found');
            });
    });
});
