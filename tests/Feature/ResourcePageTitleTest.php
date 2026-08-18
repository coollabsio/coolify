<?php

use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('renders the source name as a large page title before the navbar', function () {
    $githubApp = GithubApp::create([
        'name' => 'coolify-laravel-dev-public',
        'organization' => 'coollabsio',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_secret' => 'webhook-secret',
        'team_id' => $this->team->id,
        'is_system_wide' => false,
    ]);

    $response = $this->get(route('source.github.show', ['github_app_uuid' => $githubApp->uuid]));

    $response->assertSuccessful();
    $response->assertSee('text-[24px]! leading-7! font-semibold! tracking-tight!', false);
    $response->assertSee('order-1 mb-4 flex min-w-0 items-start justify-between gap-4 lg:order-2', false);
    $response->assertSee('order-2 lg:order-1', false);
    $response->assertSee('coolify-laravel-dev-public');
    $response->assertSee('GitHub App for coollabsio');
    $response->assertSee('>General</h3>', false);

    $html = $response->getContent();
    $titlePos = strpos($html, 'order-1 mb-4 flex items-start justify-between gap-4 lg:order-2');
    $navbarPos = strpos($html, 'order-2 lg:order-1');
    expect($titlePos)->toBeLessThan($navbarPos);
});

it('renders family page titles before tabs for team notifications and keys', function () {
    $team = $this->get(route('team.index'));
    $team->assertSuccessful();
    $team->assertSee('Team');
    $team->assertSee('Members, roles, and team settings');
    $team->assertSee('New team');
    // Desktop shell (lg+) hides the family H1; mobile topbar layout keeps it.
    expect($team->getContent())->toContain('lg:hidden');

    $this->get(route('notifications.email'))
        ->assertSuccessful()
        ->assertSee('Notifications')
        ->assertSee('Delivery channels for team events');

    $keys = $this->get(route('security.private-key.index'));
    $keys->assertSuccessful();
    $keys->assertSee('Keys &amp; Tokens', false);
    $keys->assertSee('SSH keys, cloud tokens, and API access');
    $keys->assertSee('Private Keys');
    $keys->assertSee('New private key');
    $keys->assertDontSee('SSH keys available to this team');
    expect($keys->getContent())->not->toContain('>Private keys</h2>');
});

it('renders the destination name as a large page title', function () {
    $server = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'prod-server']);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $destination->update(['name' => 'coolify']);

    $response = $this->get(route('destination.show', ['destination_uuid' => $destination->uuid]));

    $response->assertSuccessful();
    $response->assertSee('text-[24px]! leading-7! font-semibold! tracking-tight!', false);
    $response->assertSee('coolify');
    $response->assertSee('Docker network on prod-server');
    $response->assertSee('>General</h3>', false);
});

it('renders the s3 storage name as a large page title', function () {
    $storage = S3Storage::create([
        'name' => 'backup-bucket',
        'description' => 'Primary backup destination',
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'secret-key',
        'bucket' => 'coolify-backups',
        'endpoint' => 'https://s3.example.com',
        'team_id' => $this->team->id,
        'is_usable' => true,
    ]);

    $response = $this->get(route('storage.show', ['storage_uuid' => $storage->uuid]));

    $response->assertSuccessful();
    $response->assertSee('text-[24px]! leading-7! font-semibold! tracking-tight!', false);
    $response->assertSee('backup-bucket');
    $response->assertSee('Primary backup destination');
    $response->assertSee('>General</h3>', false);
});

it('uses the same page title size on application database service and server headings', function () {
    $titleClass = 'text-[24px]! leading-7! font-semibold! tracking-tight!';

    $views = [
        resource_path('views/livewire/project/application/heading.blade.php'),
        resource_path('views/livewire/project/database/heading.blade.php'),
        resource_path('views/livewire/project/service/heading.blade.php'),
        resource_path('views/livewire/server/navbar.blade.php'),
        resource_path('views/livewire/project/show.blade.php'),
        resource_path('views/livewire/project/resource/index.blade.php'),
        resource_path('views/components/dashboard/navbar.blade.php'),
        resource_path('views/livewire/server/index.blade.php'),
        resource_path('views/source/all.blade.php'),
    ];

    foreach ($views as $view) {
        expect(file_get_contents($view))->toContain($titleClass);
    }
});

it('keeps new-action buttons on the same row as page titles or in layer-2 nav', function () {
    $stackPattern = 'flex-col gap-3 sm:flex-row';

    expect(file_get_contents(resource_path('views/livewire/server/index.blade.php')))
        ->toContain($stackPattern);
    expect(file_get_contents(resource_path('views/source/all.blade.php')))
        ->toContain($stackPattern);
    expect(file_get_contents(resource_path('views/livewire/destination/index.blade.php')))
        ->toContain($stackPattern);
    expect(file_get_contents(resource_path('views/livewire/storage/index.blade.php')))
        ->toContain($stackPattern);
    expect(file_get_contents(resource_path('views/components/dashboard/navbar.blade.php')))
        ->toContain('titleActions');
    // Family pages put primary actions next to layer-2 tabs, not beside a desktop H1.
    expect(file_get_contents(resource_path('views/livewire/security/private-key/index.blade.php')))
        ->toContain('<x-slot:actions>');
    expect(file_get_contents(resource_path('views/components/team/navbar.blade.php')))
        ->toContain("'titleOnDesktop' => false")
        ->toContain('<x-slot:actions>')
        ->toContain('New team');
});
