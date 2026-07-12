<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Deployments\Index as DeploymentsIndex;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('cache.default', 'array');
    Config::set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);
    $this->withoutVite();

    $this->server = Server::factory()->create([
        'name' => 'Production Server',
        'team_id' => $this->team->id,
    ]);

    $this->project = Project::factory()->create([
        'name' => 'Storefront',
        'team_id' => $this->team->id,
    ]);

    $this->environment = Environment::factory()->create([
        'name' => 'Live',
        'project_id' => $this->project->id,
    ]);

    $this->application = Application::factory()->create([
        'name' => 'Checkout API',
        'environment_id' => $this->environment->id,
    ]);
});

function createGlobalHistoryDeployment(array $attributes = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::forceCreate(array_merge([
        'deployment_uuid' => str()->uuid()->toString(),
        'application_id' => test()->application->id,
        'application_name' => test()->application->name,
        'server_id' => test()->server->id,
        'server_name' => test()->server->name,
        'deployment_url' => '/project/project-uuid/environment/environment-uuid/application/application-uuid/deployment/deployment-uuid',
        'commit' => '1234567890abcdef',
        'commit_message' => 'Update checkout flow',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'created_at' => now(),
        'finished_at' => now()->addMinute(),
    ], $attributes));
}

it('shows paginated deployment history for the current team only', function () {
    $oldDeployment = createGlobalHistoryDeployment([
        'deployment_uuid' => 'old-deployment',
        'commit_message' => 'Old same team deployment',
        'created_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addMinute(),
    ]);
    $newDeployment = createGlobalHistoryDeployment([
        'deployment_uuid' => 'new-deployment',
        'commit_message' => 'Newest same team deployment',
        'deployment_url' => '/project/storefront/environment/production/application/checkout/deployment/new-deployment',
        'created_at' => now(),
        'finished_at' => now()->addMinute(),
    ]);

    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = Environment::factory()->create(['project_id' => $otherProject->id]);
    $otherApplication = Application::factory()->create([
        'name' => 'Other Team App',
        'environment_id' => $otherEnvironment->id,
    ]);
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'other-team-deployment',
        'application_id' => $otherApplication->id,
        'application_name' => $otherApplication->name,
        'server_id' => $otherServer->id,
        'server_name' => $otherServer->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit_message' => 'Hidden other team deployment',
        'created_at' => now()->addHour(),
        'finished_at' => now()->addHour()->addMinute(),
    ]);

    $response = $this->get('/deployments');

    $response->assertSuccessful();
    $response->assertSee('Deployments');
    $response->assertSee('Checkout API');
    $response->assertSee('Storefront');
    $response->assertSee('Production');
    $response->assertSee('Production Server');
    $response->assertSee('Newest same team deployment');
    $response->assertSee('Old same team deployment');
    $response->assertSee('\/project\/storefront\/environment\/production\/application\/checkout\/deployment\/new-deployment', false);
    $response->assertDontSee('Hidden other team deployment');
    $response->assertSeeInOrder([
        $newDeployment->deployment_uuid,
        $oldDeployment->deployment_uuid,
    ]);

    $dom = new DOMDocument;
    $previousLibxmlState = libxml_use_internal_errors(true);
    $dom->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    $xpath = new DOMXPath($dom);

    expect($xpath->query('//button[@aria-label="Previous page"]')->length)->toBe(1);
    expect($xpath->query('//button[@aria-label="Next page"]')->length)->toBe(1);
});

it('paginates global deployments', function () {
    for ($deploymentNumber = 1; $deploymentNumber <= 21; $deploymentNumber++) {
        createGlobalHistoryDeployment([
            'deployment_uuid' => "deployment-{$deploymentNumber}",
            'commit_message' => $deploymentNumber === 1
                ? 'First page unique deployment'
                : "Deployment {$deploymentNumber}",
            'created_at' => now()->subMinutes($deploymentNumber),
            'finished_at' => now()->subMinutes($deploymentNumber)->addMinute(),
        ]);
    }

    ApplicationDeploymentQueue::where('deployment_uuid', 'deployment-21')->update([
        'commit_message' => 'Last page unique deployment',
    ]);

    $this->get('/deployments')
        ->assertSuccessful()
        ->assertSee('First page unique deployment')
        ->assertDontSee('Last page unique deployment');

    $this->get('/deployments?page=2')
        ->assertSuccessful()
        ->assertSee('Last page unique deployment');
});

it('paginates global deployments through Livewire controls', function () {
    createGlobalHistoryDeployment([
        'deployment_uuid' => 'failed-deployment',
        'commit_message' => 'Filtered failed deployment',
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'created_at' => now(),
        'finished_at' => now()->addMinute(),
    ]);

    for ($deploymentNumber = 1; $deploymentNumber <= 21; $deploymentNumber++) {
        createGlobalHistoryDeployment([
            'deployment_uuid' => "livewire-deployment-{$deploymentNumber}",
            'commit_message' => "Livewire deployment {$deploymentNumber}",
            'created_at' => now()->subMinutes($deploymentNumber),
            'finished_at' => now()->subMinutes($deploymentNumber)->addMinute(),
        ]);
    }

    Livewire::test(DeploymentsIndex::class)
        ->assertSee('Livewire deployment 1')
        ->assertDontSee('Livewire deployment 21')
        ->call('nextPage')
        ->assertSee('Livewire deployment 21')
        ->assertDontSee('Livewire deployment 1')
        ->call('previousPage')
        ->assertSee('Livewire deployment 1')
        ->call('nextPage')
        ->set('status', ApplicationDeploymentStatus::FAILED->value)
        ->assertSee('Filtered failed deployment')
        ->assertDontSee('Livewire deployment 21');
});

it('filters deployments by deployment type and status', function () {
    createGlobalHistoryDeployment([
        'deployment_uuid' => 'production-finished',
        'commit_message' => 'Production finished deployment',
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
    ]);
    createGlobalHistoryDeployment([
        'deployment_uuid' => 'preview-failed',
        'commit_message' => 'Preview failed deployment',
        'pull_request_id' => 42,
        'status' => ApplicationDeploymentStatus::FAILED->value,
    ]);
    createGlobalHistoryDeployment([
        'deployment_uuid' => 'preview-queued',
        'commit_message' => 'Preview queued deployment',
        'pull_request_id' => 43,
        'status' => ApplicationDeploymentStatus::QUEUED->value,
    ]);

    $this->get('/deployments?deployment_type=production')
        ->assertSuccessful()
        ->assertSee('Production finished deployment')
        ->assertDontSee('Preview failed deployment')
        ->assertDontSee('Preview queued deployment');

    $this->get('/deployments?deployment_type=preview')
        ->assertSuccessful()
        ->assertDontSee('Production finished deployment')
        ->assertSee('Preview failed deployment')
        ->assertSee('Preview queued deployment');

    $this->get('/deployments?status=failed')
        ->assertSuccessful()
        ->assertDontSee('Production finished deployment')
        ->assertSee('Preview failed deployment')
        ->assertDontSee('Preview queued deployment');
});

it('derives project options from deployment history and filters by project', function () {
    createGlobalHistoryDeployment([
        'deployment_uuid' => 'storefront-deployment',
        'commit_message' => 'Storefront deployment',
    ]);

    $otherProject = Project::factory()->create([
        'name' => 'Marketing Site',
        'team_id' => $this->team->id,
    ]);
    $otherEnvironment = $otherProject->environments()->firstOrFail();
    $otherApplication = Application::factory()->create([
        'name' => 'Landing Page',
        'environment_id' => $otherEnvironment->id,
    ]);
    createGlobalHistoryDeployment([
        'deployment_uuid' => 'marketing-deployment',
        'application_id' => $otherApplication->id,
        'application_name' => $otherApplication->name,
        'commit_message' => 'Marketing deployment',
    ]);

    $projectWithoutDeployments = Project::factory()->create([
        'name' => 'Empty Project',
        'team_id' => $this->team->id,
    ]);

    $component = Livewire::test(DeploymentsIndex::class);

    expect($component->instance()->projectOptions())
        ->toHaveKey((string) $this->project->id, $this->project->name)
        ->toHaveKey((string) $otherProject->id, $otherProject->name)
        ->not->toHaveKey((string) $projectWithoutDeployments->id);

    $component
        ->set('project', (string) $otherProject->id)
        ->assertSee('Marketing deployment')
        ->assertDontSee('Storefront deployment');
});

it('derives server options from deployment history and filters by server', function () {
    createGlobalHistoryDeployment([
        'deployment_uuid' => 'primary-server-deployment',
        'commit_message' => 'Primary server deployment',
    ]);

    $secondaryServer = Server::factory()->create([
        'name' => 'Secondary Server',
        'team_id' => $this->team->id,
    ]);
    createGlobalHistoryDeployment([
        'deployment_uuid' => 'secondary-server-deployment',
        'server_id' => $secondaryServer->id,
        'server_name' => $secondaryServer->name,
        'commit_message' => 'Secondary server deployment',
    ]);

    $serverWithoutDeployments = Server::factory()->create([
        'name' => 'Empty Server',
        'team_id' => $this->team->id,
    ]);

    $component = Livewire::test(DeploymentsIndex::class);

    expect($component->instance()->serverOptions())
        ->toHaveKey((string) $this->server->id, $this->server->name)
        ->toHaveKey((string) $secondaryServer->id, $secondaryServer->name)
        ->not->toHaveKey((string) $serverWithoutDeployments->id);

    $component
        ->set('server', (string) $secondaryServer->id)
        ->assertSee('Secondary server deployment')
        ->assertDontSee('Primary server deployment');
});

it('clears unavailable project and server filters with an error', function () {
    createGlobalHistoryDeployment();

    $component = Livewire::withQueryParams([
        'project' => '999999',
        'server' => '999999',
    ])->test(DeploymentsIndex::class)
        ->assertSet('project', 'all')
        ->assertSet('server', 'all')
        ->assertDispatched('error', 'Selected project is unavailable.')
        ->assertDispatched('error', 'Selected server is unavailable.');

    expect(collect($component->effects['xjs'])->pluck('expression')->all())
        ->toContain(
            "const url = new URL(window.location.href); url.searchParams.delete('project'); window.history.replaceState(window.history.state, '', url);",
            "const url = new URL(window.location.href); url.searchParams.delete('server'); window.history.replaceState(window.history.state, '', url);",
        );
});

it('resets pagination when deployment filters change or are cleared', function () {
    for ($deploymentNumber = 1; $deploymentNumber <= 21; $deploymentNumber++) {
        createGlobalHistoryDeployment([
            'deployment_uuid' => "filter-page-deployment-{$deploymentNumber}",
            'created_at' => now()->subMinutes($deploymentNumber),
        ]);
    }

    Livewire::test(DeploymentsIndex::class)
        ->call('nextPage')
        ->assertSet('paginators.page', 2)
        ->set('project', (string) $this->project->id)
        ->assertSet('paginators.page', 1)
        ->call('nextPage')
        ->set('server', (string) $this->server->id)
        ->assertSet('paginators.page', 1)
        ->set('status', ApplicationDeploymentStatus::FINISHED->value)
        ->call('clearFilters')
        ->assertSet('project', 'all')
        ->assertSet('server', 'all')
        ->assertSet('deployment_type', 'all')
        ->assertSet('status', 'all')
        ->assertSet('paginators.page', 1);
});

it('shows deployment-derived filters and hides a singleton server filter', function () {
    createGlobalHistoryDeployment([
        'commit_message' => 'Only server deployment',
    ]);

    Livewire::test(DeploymentsIndex::class)
        ->assertSeeHtml('name=project')
        ->assertDontSeeHtml('name=server')
        ->assertSeeHtml('wire:target="project"')
        ->assertSeeHtml('wire:target="deployment_type"')
        ->assertSeeHtml('wire:target="status"')
        ->assertDontSee('Clear filters')
        ->set('status', ApplicationDeploymentStatus::FINISHED->value)
        ->assertSee('Clear filters');
});

it('keeps deployment row navigation separate from commit links', function () {
    $deploymentUrl = '/project/storefront/environment/production/application/checkout/deployment/deployment-with-url';

    createGlobalHistoryDeployment([
        'deployment_uuid' => 'deployment-with-url',
        'deployment_url' => $deploymentUrl,
        'commit_message' => 'Deployment with URL',
    ]);

    $response = $this->get('/deployments')
        ->assertSuccessful()
        ->assertSee('Deployment with URL')
        ->assertSee(str_replace('/', '\/', $deploymentUrl), false)
        ->assertSee('window.Livewire?.navigate', false);

    $dom = new DOMDocument;
    $previousLibxmlState = libxml_use_internal_errors(true);
    $dom->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    $xpath = new DOMXPath($dom);
    $deploymentUrlAnchors = $xpath->query('//*[@data-deployment-uuid="deployment-with-url"]//a[@href="'.$deploymentUrl.'"]');
    $commitAnchors = $xpath->query('//*[@data-deployment-uuid="deployment-with-url"]//a[@target="_blank" and contains(@rel, "noopener")]');
    $deploymentRows = $xpath->query('//*[@data-deployment-uuid="deployment-with-url" and @role="link" and @tabindex="0"]');

    expect($deploymentUrlAnchors->length)->toBe(0);
    expect($commitAnchors->length)->toBe(1);
    expect($deploymentRows->length)->toBe(1);
});

it('renders deployments without log urls as non-clickable rows', function () {
    createGlobalHistoryDeployment([
        'deployment_uuid' => 'deployment-without-url',
        'deployment_url' => null,
        'commit_message' => 'Deployment without URL',
    ]);

    $response = $this->get('/deployments')
        ->assertSuccessful()
        ->assertSee('Deployment without URL');

    $dom = new DOMDocument;
    $previousLibxmlState = libxml_use_internal_errors(true);
    $dom->loadHTML($response->getContent());
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    $anchors = (new DOMXPath($dom))->query('//*[@data-deployment-uuid="deployment-without-url"]//a[contains(concat(" ", normalize-space(@class), " "), " block ")]');

    expect($anchors->length)->toBe(0);
});
