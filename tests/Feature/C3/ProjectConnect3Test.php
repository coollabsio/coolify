<?php

/**
 * Connect3 fork: Project/Application model behaviour (PRD 4.1, 4.2, 5.1).
 */

use App\Actions\Shared\CheckDomainDns;
use App\Livewire\Project\C3Settings;
use App\Livewire\Settings\C3;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const C3_FEATURE_APEX = 'sites.c3-staging.test';

beforeEach(function () {
    config(['app.env' => 'local', 'cache.default' => 'array', 'queue.default' => 'sync']);
    $this->withoutVite();
    Notification::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true, 'c3_staging_apex' => C3_FEATURE_APEX],
    ));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'ip' => '203.0.113.10']);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
});

function c3MakeApplication(Project $project, $destination, array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => $project->environments()->first()->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ], $attributes));
}

describe('project fields', function () {
    test('a slugged project gets staging credentials and defaults to staged', function () {
        $project = Project::create(['name' => 'Todd Plumbing', 'team_id' => $this->team->id, 'client_slug' => 'Todd Plumbing']);

        expect($project->client_slug)->toBe('todd-plumbing')
            ->and($project->site_state)->toBe('staged')
            ->and($project->staging_auth_user)->toBe('todd-plumbing')
            ->and(strlen($project->staging_auth_pass))->toBeGreaterThanOrEqual(20)
            ->and($project->stagingUrl())->toBe('https://todd-plumbing.'.C3_FEATURE_APEX)
            ->and($project->stagingHost('pr-3'))->toBe('pr-3--todd-plumbing.'.C3_FEATURE_APEX);
    });

    test('the staging password is encrypted at rest and hidden from serialisation', function () {
        $project = Project::create(['name' => 'X', 'team_id' => $this->team->id, 'client_slug' => 'x-client']);

        expect($project->getRawOriginal('staging_auth_pass'))->not->toBe($project->staging_auth_pass)
            ->and($project->toArray())->not->toHaveKey('staging_auth_pass');
    });

    test('a slug with the reserved double dash is rejected', function () {
        expect(fn () => Project::create(['name' => 'X', 'team_id' => $this->team->id, 'client_slug' => 'pr-1--todd']))
            ->toThrow(ValidationException::class);
    });

    test('projects without a slug are untouched', function () {
        $project = Project::create(['name' => 'Plain', 'team_id' => $this->team->id]);

        expect($project->client_slug)->toBeNull()
            ->and($project->staging_auth_pass)->toBeNull()
            ->and($project->stagingUrl())->toBeNull()
            ->and($project->c3Enabled())->toBeFalse();
    });
});

describe('application staging hostnames', function () {
    test('the default generated domain is replaced with the slug hostname and preview template', function () {
        $project = Project::create(['name' => 'Todd', 'team_id' => $this->team->id, 'client_slug' => 'todd-plumbing']);
        $app = c3MakeApplication($project, $this->destination);

        $app->fqdn = generateUrl(server: $this->server, random: $app->uuid);
        $app->save();

        expect($app->fqdn)->toBe('https://todd-plumbing.'.C3_FEATURE_APEX)
            ->and($app->preview_url_template)->toBe('pr-{{pr_id}}--{{domain}}')
            ->and($project->primaryApplication()?->id)->toBe($app->id)
            ->and($project->primaryDockerServiceName())->toBe("https-0-{$app->uuid}");

        $preview = new ApplicationPreview(['application_id' => $app->id, 'pull_request_id' => 12]);
        $preview->setRelation('application', $app);
        expect(data_get($preview->generatedPreviewDomain($app->fqdn), 'url'))->toBe('https://pr-12--todd-plumbing.'.C3_FEATURE_APEX);
    });

    test('a second application in the project gets a name prefix', function () {
        $project = Project::create(['name' => 'Todd', 'team_id' => $this->team->id, 'client_slug' => 'todd-plumbing']);
        $first = c3MakeApplication($project, $this->destination);
        $first->fqdn = generateUrl(server: $this->server, random: $first->uuid);
        $first->save();

        $second = c3MakeApplication($project, $this->destination, ['name' => 'Booking API']);
        $second->fqdn = generateUrl(server: $this->server, random: $second->uuid);
        $second->save();

        expect($second->fqdn)->toBe('https://booking-api--todd-plumbing.'.C3_FEATURE_APEX)
            ->and($project->primaryApplication()?->id)->toBe($first->id);
    });

    test('operator chosen domains are never rewritten', function () {
        $project = Project::create(['name' => 'Todd', 'team_id' => $this->team->id, 'client_slug' => 'todd-plumbing']);
        $app = c3MakeApplication($project, $this->destination);

        $app->fqdn = 'https://preview.example.com';
        $app->save();

        expect($app->fqdn)->toBe('https://preview.example.com');
    });

    test('projects without a slug keep the generated default', function () {
        $project = Project::create(['name' => 'Plain', 'team_id' => $this->team->id]);
        $app = c3MakeApplication($project, $this->destination);
        $default = generateUrl(server: $this->server, random: $app->uuid);

        $app->fqdn = $default;
        $app->save();

        expect($app->fqdn)->toBe($default);
    });

    test('without a staging apex nothing is rewritten', function () {
        InstanceSettings::findOrFail(0)->update(['c3_staging_apex' => null]);
        $project = Project::create(['name' => 'Todd', 'team_id' => $this->team->id, 'client_slug' => 'todd-plumbing']);
        $app = c3MakeApplication($project, $this->destination);
        $default = generateUrl(server: $this->server, random: $app->uuid);

        $app->fqdn = $default;
        $app->save();

        expect($app->fqdn)->toBe($default);
    });

    test('the project proxy config reflects state and live domains', function () {
        $project = Project::create(['name' => 'Todd', 'team_id' => $this->team->id, 'client_slug' => 'todd-plumbing']);
        $app = c3MakeApplication($project, $this->destination);
        $app->fqdn = generateUrl(server: $this->server, random: $app->uuid);
        $app->save();

        $staged = $project->c3DynamicConfig();
        expect(data_get($staged, 'http.middlewares.c3-todd-plumbing-auth.basicAuth.users.0'))->toStartWith('todd-plumbing:$2y$')
            ->and(data_get($staged, 'http.routers'))->toBeNull();

        $project->update(['site_state' => 'live', 'live_domains' => ['example.com']]);
        $live = $project->fresh()->c3DynamicConfig();
        expect(data_get($live, 'http.routers.c3-todd-plumbing-live-0.service'))->toBe("https-0-{$app->uuid}@docker");
    });
});

test('the robots override route is public and disallows everything', function () {
    $response = $this->get('/c3/robots.txt');

    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet')
        ->assertSee('Disallow: /', false);
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
});

describe('livewire', function () {
    test('the project card renders, saves a slug and blocks promotion on failed DNS', function () {
        $project = Project::create(['name' => 'Todd', 'team_id' => $this->team->id]);
        $this->actingAs($this->user);

        $component = Livewire::test(C3Settings::class, ['project' => $project])
            ->assertSee('Connect3 site')
            ->set('client_slug', 'Todd Plumbing')
            ->set('live_domains', 'example.com')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('Staging access')
            ->assertSee('https://todd-plumbing.'.C3_FEATURE_APEX);

        expect($project->fresh()->client_slug)->toBe('todd-plumbing');

        $component->set('client_slug', 'pr-1--todd')->call('submit')->assertHasErrors(['client_slug']);

        $app = c3MakeApplication($project->fresh(), $this->destination, ['fqdn' => 'https://todd-plumbing.'.C3_FEATURE_APEX]);
        CheckDomainDns::mock()->shouldReceive('handle')->once()->andReturn([
            'example.com' => ['status' => 'failed', 'message' => 'nope', 'expected_ip' => '203.0.113.10', 'checked_at' => now()->toIso8601String()],
        ]);
        $component->set('client_slug', 'todd-plumbing')->call('promote')->assertDispatched('error');
        expect($project->fresh()->site_state)->toBe('staged');
    });

    test('members without update rights cannot change the card', function () {
        $project = Project::create(['name' => 'Todd', 'team_id' => $this->team->id, 'client_slug' => 'todd']);
        $member = User::factory()->create();
        $member->teams()->attach($this->team, ['role' => 'member']);
        $this->actingAs($member);

        Livewire::test(C3Settings::class, ['project' => $project])
            ->set('client_slug', 'hijack')
            ->call('submit');

        expect($project->fresh()->client_slug)->toBe('todd');
    });

    test('the instance settings card saves the apex and token', function () {
        $rootTeam = Team::find(0) ?? Team::factory()->create(['id' => 0]);
        $this->user->teams()->attach($rootTeam, ['role' => 'owner']);
        $this->actingAs($this->user->fresh());

        Livewire::test(C3::class)
            ->assertSee('Connect3 staging')
            ->set('c3_staging_apex', 'https://Sites.Example.test/')
            ->set('c3_cloudflare_dns_token', 'cf-token')
            ->call('submit')
            ->assertHasNoErrors();

        $settings = InstanceSettings::findOrFail(0);
        expect($settings->c3_staging_apex)->toBe('sites.example.test')
            ->and($settings->c3_cloudflare_dns_token)->toBe('cf-token')
            ->and($settings->getRawOriginal('c3_cloudflare_dns_token'))->not->toBe('cf-token');
    });
});
