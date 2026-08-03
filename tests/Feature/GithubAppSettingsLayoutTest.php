<?php

use App\Livewire\Source\Github\Change;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    InstanceSettings::forceCreate([
        'id' => 0,
        'fqdn' => null,
        'public_ipv4' => null,
        'public_ipv6' => null,
    ]);
});

it('places connection status next to the title and delete in the danger zone view', function () {
    $view = file_get_contents(resource_path('views/livewire/source/github/change.blade.php'));
    $navbar = file_get_contents(resource_path('views/components/dashboard/navbar.blade.php'));

    expect($view)
        ->toContain('<x-slot:titleMeta>')
        ->toContain('label="Connected"')
        ->toContain('source.github.danger')
        ->toContain('Danger Zone')
        ->toContain('github-app-danger-section')
        ->toContain('submitAction="delete"');

    // Connection status lives in titleMeta; the dashboard navbar has no actions slot.
    $navbarStart = strpos($view, '<x-dashboard.navbar');
    $navbarEnd = strpos($view, '</x-dashboard.navbar>');
    expect($navbarStart)->not->toBeFalse();
    expect($navbarEnd)->not->toBeFalse();
    $navbarBlock = substr($view, $navbarStart, $navbarEnd - $navbarStart);
    expect($navbarBlock)
        ->toContain('<x-slot:titleMeta>')
        ->not->toContain('<x-slot:actions>');

    expect($navbar)
        ->toContain('@isset($titleMeta)')
        ->toContain("request()->routeIs('source.github.show', 'source.github.danger')");

    expect(file_get_contents(base_path('routes/web.php')))
        ->toContain("->name('source.github.danger')");
});

it('sets the danger active tab from the danger route', function () {
    $githubApp = GithubApp::create([
        'uuid' => (string) str()->uuid(),
        'name' => 'Layout Test App',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $this->team->id,
        'app_id' => 12345,
        'installation_id' => 67890,
        'client_id' => 'client',
        'client_secret' => 'secret',
        'webhook_secret' => 'webhook',
        'is_system_wide' => false,
    ]);

    $this->get(route('source.github.danger', ['github_app_uuid' => $githubApp->uuid]))
        ->assertOk()
        ->assertSee('Danger zone', false)
        ->assertSee('Delete GitHub App', false)
        ->assertSee('Delete', false);

    Livewire::withQueryParams(['github_app_uuid' => $githubApp->uuid])
        ->test(Change::class)
        ->assertSet('activeTab', 'general');
});
