<?php

use App\Enums\ApplicationDeploymentStatus;
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
    $response->assertSee('/project/storefront/environment/production/application/checkout/deployment/new-deployment', false);
    $response->assertDontSee('Hidden other team deployment');
    $response->assertSeeInOrder([
        $newDeployment->deployment_uuid,
        $oldDeployment->deployment_uuid,
    ]);
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
