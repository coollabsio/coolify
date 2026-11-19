<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Project\Application\Deployment\Index;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('moves deployment history pagination by one page per action', function () {
    expect((new ReflectionMethod(Index::class, 'nextPage'))->getNumberOfParameters())->toBe(0)
        ->and((new ReflectionMethod(Index::class, 'previousPage'))->getNumberOfParameters())->toBe(0);
});

it('filters deployment history by server', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $firstServer = Server::factory()->create(['team_id' => $team->id, 'name' => 'Primary']);
    $secondServer = Server::factory()->create(['team_id' => $team->id, 'name' => 'Secondary']);
    $destination = StandaloneDocker::query()->where('server_id', $firstServer->id)->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    foreach ([$firstServer, $secondServer] as $server) {
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $application->id,
            'deployment_uuid' => "deployment-{$server->id}",
            'server_id' => $server->id,
            'server_name' => $server->name,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
        ]);
    }

    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'webhook-deployment',
        'server_id' => $secondServer->id,
        'server_name' => $secondServer->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'is_webhook' => true,
    ]);

    $result = $application->deployments(filters: [
        'status:finished',
        'source:manual',
        'source:webhook',
        "server:{$secondServer->id}",
    ]);

    expect($result['count'])->toBe(2)
        ->and($result['deployments']->pluck('server_id')->unique()->sole())->toBe($secondServer->id);
});

it('keeps a pull request filter from the URL when it has no deployment records yet', function () {
    $application = Application::factory()->create();
    $component = new Index;
    $component->application = $application;
    $component->pull_request_id = '41';

    $method = new ReflectionMethod(Index::class, 'loadPullRequestOptions');
    $method->invoke($component);

    expect($component->pull_request_id)->toBe('41')
        ->and($component->pullRequestOptions)->toContain([
            'value' => '41',
            'label' => 'Pull request #41',
        ]);
});

it('includes every configured preview in the pull request filter options', function () {
    $application = Application::factory()->create();
    foreach ([41, 72] as $pullRequestId) {
        ApplicationPreview::query()->create([
            'application_id' => $application->id,
            'pull_request_id' => $pullRequestId,
            'pull_request_html_url' => "https://github.com/example/repository/pull/{$pullRequestId}",
        ]);
    }

    $component = new Index;
    $component->application = $application;

    $method = new ReflectionMethod(Index::class, 'loadPullRequestOptions');
    $method->invoke($component);

    expect($component->pullRequestOptions)->toBe([
        ['value' => '', 'label' => 'All deployments'],
        ['value' => '72', 'label' => 'Pull request #72'],
        ['value' => '41', 'label' => 'Pull request #41'],
    ]);
});
