<?php

use App\Livewire\Dashboard;
use App\Livewire\Dashboard\TrafficAnalytics;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

class FakeDashboardTrafficClient extends SentinelTrafficClient
{
    public array $responses = [];

    protected function raw(string $url): string
    {
        foreach ($this->responses as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }

        return '{}';
    }
}

class FailingDashboardTrafficClient extends SentinelTrafficClient
{
    protected function raw(string $url): string
    {
        throw new RuntimeException('Server unreachable');
    }
}

function fakeDashboardTrafficResponses(int $requests = 1000): array
{
    return [
        '/traffic/apps' => json_encode([]),
        '/traffic/overview' => json_encode([
            'requests' => $requests,
            'bytes_in' => 5000,
            'bytes_out' => 25000,
            'status' => ['s2xx' => 900, 's3xx' => 50, 's4xx' => 40, 's5xx' => 10],
            'latency' => ['p50' => 12.5, 'p95' => 45.2, 'p99' => 90.1],
            'unique_visitors' => 320,
        ]),
        '/traffic/breakdown/country' => json_encode([
            ['value' => 'US', 'requests' => 600, 'bytes_out' => 15000],
        ]),
    ];
}

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

it('hides traffic analytics from the dashboard when no server has it enabled', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertDontSee('Traffic analytics');
});

it('renders the team traffic summary aggregated across servers with an approximate badge', function () {
    $serverOne = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $serverOne->settings->is_traffic_analytics_enabled = true;
    $serverOne->settings->save();

    $serverTwo = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $serverTwo->settings->is_traffic_analytics_enabled = true;
    $serverTwo->settings->save();

    $fakeOne = new FakeDashboardTrafficClient($serverOne);
    $fakeOne->responses = fakeDashboardTrafficResponses(1000);

    $fakeTwo = new FakeDashboardTrafficClient($serverTwo);
    $fakeTwo->responses = fakeDashboardTrafficResponses(500);

    app()->bind(SentinelTrafficClient::class, function ($app, $params) use ($serverOne, $fakeOne, $fakeTwo) {
        $server = $params['server'] ?? null;

        return $server && $server->is($serverOne) ? $fakeOne : $fakeTwo;
    });

    loadLazy(Livewire::test(TrafficAnalytics::class))
        ->assertOk()
        ->assertSee('Requests')
        ->assertSee('1,500')
        ->assertSee('Unique visitors')
        ->assertSee('approximate');
});

it('shows only sparkline KPI cards that link through to the full analytics page', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $server->settings->is_traffic_analytics_enabled = true;
    $server->settings->save();

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherDestination = StandaloneDocker::factory()->create(['server_id' => $otherServer->id, 'network' => 'other-team-test']);

    $otherTeamApplication = Application::factory()->create([
        'name' => 'Secret Other Team App',
        'environment_id' => $otherEnvironment->id,
        'destination_id' => $otherDestination->id,
        'destination_type' => StandaloneDocker::class,
    ]);

    $fake = new FakeDashboardTrafficClient($server);
    $fake->responses = fakeDashboardTrafficResponses(1000);
    $fake->responses['/traffic/apps'] = json_encode([$otherTeamApplication->uuid]);

    app()->bind(SentinelTrafficClient::class, fn () => $fake);

    loadLazy(Livewire::test(TrafficAnalytics::class))
        ->assertOk()
        // The slimmed dashboard shows only KPI cards + a link to /analytics — no per-app rows.
        ->assertSee('Open analytics')
        ->assertSee(route('analytics'), false)
        ->assertDontSee('Top applications')
        ->assertDontSee('Secret Other Team App')
        ->assertDontSee($otherTeamApplication->uuid);
});

it('shows loading states while the dashboard range refreshes', function () {
    $view = file_get_contents(resource_path('views/livewire/dashboard/traffic-analytics.blade.php'));

    expect($view)
        ->toContain('wire:loading.attr="disabled" wire:target="setRange"')
        ->toContain('wire:loading.class="invisible" wire:target="setRange(\'24h\')"')
        ->toContain('wire:loading wire:target="setRange(\'7d\')"')
        ->toContain('wire:loading wire:target="setRange(\'30d\')"')
        ->toContain('aria-label="Loading analytics"');
});

it('styles the open analytics link as a dashboard action button', function () {
    $view = file_get_contents(resource_path('views/livewire/dashboard/traffic-analytics.blade.php'));

    expect($view)
        ->toContain('class="group inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md border border-neutral-200 bg-white px-2.5')
        ->toContain('group-hover:translate-x-0.5');
});

it('uses the dashboard surface treatment for the analytics KPI group', function () {
    $view = file_get_contents(resource_path('views/livewire/dashboard/traffic-analytics.blade.php'));

    expect($view)
        ->toContain('rounded-xl border border-neutral-200 bg-neutral-200')
        ->toContain('dark:border-white/[0.08] dark:bg-white/[0.07]')
        ->toContain('dark:bg-[color-mix(in_srgb,var(--color-app)_95%,white)]')
        ->toContain('dark:hover:bg-[color-mix(in_srgb,var(--color-app)_93%,white)]')
        ->not->toContain('rounded-xl bg-neutral-200 ring-1 ring-neutral-200')
        ->not->toContain('dark:bg-base dark:hover:bg-white/[0.03]');
});

it('hides dashboard analytics when every server fetch fails', function () {
    $serverOne = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $serverOne->settings->is_traffic_analytics_enabled = true;
    $serverOne->settings->save();

    $serverTwo = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $serverTwo->settings->is_traffic_analytics_enabled = true;
    $serverTwo->settings->save();

    app()->bind(SentinelTrafficClient::class, function ($app, $params) {
        return new FailingDashboardTrafficClient($params['server']);
    });

    loadLazy(Livewire::test(TrafficAnalytics::class))
        ->assertOk()
        ->assertSeeHtml('class="contents"')
        ->assertDontSee('Traffic analytics')
        ->assertDontSee('No analytics data yet')
        ->assertDontSee('Unique visitors')
        ->assertDontSee('Error rate');
});

it('renders nothing when no server in the team has traffic analytics enabled', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    // New servers default analytics on; this scenario is the all-disabled team.
    $server->settings->is_traffic_analytics_enabled = false;
    $server->settings->save();

    loadLazy(Livewire::test(TrafficAnalytics::class))
        ->assertOk()
        ->assertDontSee('Unique visitors')
        ->assertDontSee('Traffic analytics');
});

it('uses an empty lazy placeholder so analytics only appears after data loads', function () {
    $view = file_get_contents(resource_path('views/livewire/dashboard/traffic-analytics-placeholder.blade.php'));

    expect($view)
        ->toContain('class="contents"')
        ->not->toContain('Traffic analytics');
});
