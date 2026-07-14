<?php

use App\Jobs\SendMessageToSlackJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Notifications\Application\DeploymentFailed;
use App\Notifications\Application\DeploymentSuccess;
use App\Notifications\Channels\SlackChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('prefixes a Slack title with the project name when enabled', function () {
    Queue::fake();

    $team = Team::factory()->create();
    $project = Project::factory()->create([
        'name' => 'My Project',
        'team_id' => $team->id,
    ]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);

    expect($team->slackNotificationSettings->include_project_name_in_title)->toBeFalse();

    $team->slackNotificationSettings->update([
        'slack_enabled' => true,
        'slack_webhook_url' => 'https://hooks.slack.com/services/test',
        'include_project_name_in_title' => true,
    ]);
    $notification = new DeploymentFailed($application, 'deployment-uuid');
    $message = $notification->toSlack();

    app(SlackChannel::class)->send($team, $notification);

    expect($message->projectName)->toBe('My Project');
    Queue::assertPushed(SendMessageToSlackJob::class, function (SendMessageToSlackJob $job) {
        $message = (new ReflectionProperty($job, 'message'))->getValue($job);

        return $message->title === 'My Project: Deployment failed';
    });
});

it('keeps the original Slack title when the project name option is disabled', function () {
    Queue::fake();

    $team = Team::factory()->create();
    $project = Project::factory()->create([
        'name' => 'My Project',
        'team_id' => $team->id,
    ]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    $team->slackNotificationSettings->update([
        'slack_enabled' => true,
        'slack_webhook_url' => 'https://hooks.slack.com/services/test',
        'include_project_name_in_title' => false,
    ]);
    $notification = new DeploymentSuccess($application, 'deployment-uuid');
    $message = $notification->toSlack();

    app(SlackChannel::class)->send($team, $notification);

    expect($message->projectName)->toBe('My Project');
    Queue::assertPushed(SendMessageToSlackJob::class, function (SendMessageToSlackJob $job) {
        $message = (new ReflectionProperty($job, 'message'))->getValue($job);

        return $message->title === 'New version successfully deployed';
    });
});
