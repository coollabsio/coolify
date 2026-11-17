<?php

use App\Exceptions\DeploymentException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

const BASE_BRANCH_COMPOSE = "services:\n  web:\n    image: nginx:alpine\n";

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'git_repository' => 'https://github.com/coollabsio/compose-app',
        'git_branch' => 'main',
        'base_directory' => '/',
        'docker_compose_location' => '/docker-compose.yml',
        'docker_compose_raw' => BASE_BRANCH_COMPOSE,
    ])->fresh();
});

/**
 * A pull request deployment job whose checkout has $checkoutOutput as the output of the Compose file read.
 *
 * @param  array<int, array{0: string, 1: string}>  $logEntries
 * @param  array<int, string>  $commands
 */
function pullRequestComposeJob(Application $application, ?string $checkoutOutput, array &$logEntries, array &$commands = [], ?ApplicationPreview $preview = null): ApplicationDeploymentJob
{
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('addLogEntry')->andReturnUsing(function (string $message, string $type = 'stdout', bool $hidden = false) use (&$logEntries) {
        $logEntries[] = [$message, $type, $hidden];
    });

    $outputs = collect();
    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();
    $job->shouldReceive('execute_remote_command')->andReturnUsing(function (array $command) use ($outputs, $checkoutOutput, &$commands) {
        $commands[] = $command[0];
        $outputs->put($command['save'], $checkoutOutput);
    });
    foreach ([
        'application' => $application,
        'application_deployment_queue' => $queue,
        'pull_request_id' => 42,
        'preview' => $preview,
        'deployment_uuid' => 'deployment-uuid',
        'basedir' => '/artifacts/deployment-uuid',
        'workdir' => '/artifacts/deployment-uuid',
        'docker_compose_location' => '/docker-compose.yml',
        'saved_outputs' => $outputs,
    ] as $property => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $property))->setValue($job, $value);
    }

    return $job;
}

/**
 * A preview of pull request #42. Only the webhooks and the pull request list know that its branch is in the repository itself.
 */
function pullRequestComposePreview(Application $application, bool $isSameRepository = true): ApplicationPreview
{
    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/coollabsio/compose-app/pull/42',
    ]);
    if ($isSameRepository) {
        $preview->forceFill(['is_same_repository' => true])->save();
    }

    return $preview->fresh();
}

function loadComposeForDeployment(ApplicationDeploymentJob $job): void
{
    (new ReflectionMethod(ApplicationDeploymentJob::class, 'loadComposeFileForDeployment'))->invoke($job);
}

test('a preview deploys the Compose file of its pull request and keeps the file of the base branch', function () {
    $pullRequestCompose = "services:\n  web:\n    image: nginx:1.27\n  worker:\n    image: redis:7\n    volumes:\n      - type: bind\n        source: ./worker.conf\n        target: /etc/worker.conf\n        content: from-pr\n";
    // The parser saves the file storage, which starts a write on the server.
    Queue::fake();
    Process::fake();
    $logEntries = [];
    $commands = [];
    $preview = pullRequestComposePreview($this->application);
    $job = pullRequestComposeJob($this->application, $pullRequestCompose."\n", $logEntries, $commands, $preview);

    loadComposeForDeployment($job);
    $parsed = $this->application->parse(pull_request_id: 42, preview_id: $preview->id);

    expect($commands[0])->toStartWith('docker exec deployment-uuid bash -c')
        ->toContain('/artifacts/deployment-uuid/docker-compose.yml')
        ->and($parsed['services']->keys()->all())->toBe(['web', 'worker'])
        ->and($this->application->docker_compose_raw)->toBe(trim($pullRequestCompose))
        ->and($this->application->fresh()->docker_compose_raw)->toBe(BASE_BRANCH_COMPOSE)
        ->and($this->application->fresh()->docker_compose)->toBeNull()
        ->and(collect($logEntries)->pluck(0))->toContain('Using the Docker Compose file at /docker-compose.yml of pull request #42.');
});

test('a preview parse with the Compose file of its pull request does not change the variables and file storages of the application', function () {
    Queue::fake();
    Process::fake();
    $volume = fn (string $content): string => "    volumes:\n      - type: bind\n        source: ./app.conf\n        target: /etc/app.conf\n        content: {$content}\n";
    $this->application->update(['docker_compose_raw' => "services:\n  web:\n    image: nginx:alpine\n    environment:\n      EXISTING: \${EXISTING}\n".$volume('from-base')]);
    $this->application->parse();
    $variablesBefore = $this->application->environment_variables()->orderBy('key')->get()->map->only(['key', 'value', 'is_required', 'is_preview'])->all();
    $fileStoragesBefore = $this->application->fileStorages()->get(['fs_path', 'mount_path', 'content', 'is_directory'])->toArray();
    $pullRequestCompose = "services:\n  web:\n    image: nginx:1.27\n    environment:\n      EXISTING: \${EXISTING:?}\n      NEW_DEFAULT: \${NEW_DEFAULT:-from-pr}\n      NEW_PLAIN: \${NEW_PLAIN}\n".$volume('from-pr')
        ."      - type: bind\n        source: ./extra.conf\n        target: /etc/extra.conf\n        content: from-pr\n";
    $preview = pullRequestComposePreview($this->application);
    $logEntries = [];
    $application = $this->application->fresh();

    loadComposeForDeployment(pullRequestComposeJob($application, $pullRequestCompose, $logEntries, preview: $preview));
    $parsed = $application->parse(pull_request_id: 42, preview_id: $preview->id);

    expect(data_get($parsed, 'services.web.image'))->toBe('nginx:1.27')
        ->and(data_get($parsed, 'services.web.environment.NEW_DEFAULT'))->toBe('${NEW_DEFAULT:-from-pr}')
        ->and(collect(data_get($parsed, 'services.web.volumes'))->pluck('target')->all())->toContain('/etc/app.conf', '/etc/extra.conf')
        ->and($this->application->environment_variables()->orderBy('key')->get()->map->only(['key', 'value', 'is_required', 'is_preview'])->all())->toBe($variablesBefore)
        ->and($this->application->fileStorages()->get(['fs_path', 'mount_path', 'content', 'is_directory'])->toArray())->toBe($fileStoragesBefore);
});

test('a preview stops when the Compose file of its pull request cannot be used', function (string $checkoutOutput, string $reason) {
    $logEntries = [];
    $job = pullRequestComposeJob($this->application, $checkoutOutput, $logEntries, preview: pullRequestComposePreview($this->application));

    expect(fn () => loadComposeForDeployment($job))
        ->toThrow(DeploymentException::class, "Deployment stopped: the Docker Compose file at /docker-compose.yml (pull request #42) {$reason}");

    expect(collect($logEntries)->where(1, 'stderr')->pluck(0)->implode("\n"))->toContain($reason)
        ->and($this->application->docker_compose_raw)->toBe(BASE_BRANCH_COMPOSE)
        ->and($this->application->fresh()->docker_compose_raw)->toBe(BASE_BRANCH_COMPOSE);
})->with([
    'missing' => ["__COOLIFY_COMPOSE_NOT_FOUND__\n", 'does not exist.'],
    'symbolic link' => ["__COOLIFY_COMPOSE_SYMLINK__\n", 'is a symbolic link.'],
    'too large' => ["__COOLIFY_COMPOSE_TOO_LARGE__\n", 'exceeds the 5 MiB size limit.'],
    'empty' => ['', 'is empty.'],
    'not safe' => ["services:\n  'web;touch /tmp/pwned':\n    image: nginx\n", 'is not safe to use, so Coolify did not load it.'],
]);

test('a preview uses the Compose file of the base branch when it can come from a fork or uses raw Compose', function (array $settings, bool $isSameRepository, string $reason) {
    $this->application->settings->update($settings);
    $preview = pullRequestComposePreview($this->application, $isSameRepository);
    Process::fake(fn ($process) => Process::result(output: str_contains(implode(' ', (array) remoteProcessScript($process)), 'git cat-file blob') ? "services:\n  base:\n    image: nginx\n" : ''));
    $logEntries = [];
    $commands = [];

    loadComposeForDeployment(pullRequestComposeJob($this->application, "services:\n  from-pr:\n    image: nginx\n", $logEntries, $commands, $preview));

    expect($commands)->toBe([])
        ->and($this->application->fresh()->docker_compose_raw)->toBe("services:\n  base:\n    image: nginx")
        ->and(collect($logEntries)->pluck(0))->toContain("This preview uses the Docker Compose file of the main branch, because {$reason}.");
})->with([
    'public PR deployments' => [['is_pr_deployments_public_enabled' => true], true, 'public PR deployments are on, and a pull request from a fork could add host mounts to it'],
    'raw Compose deployment' => [['is_raw_compose_deployment_enabled' => true], true, 'raw Compose deployments are on'],
    // A preview added by hand or through the API can be a pull request from a fork.
    'pull request that is not known to come from the repository' => [[], false, 'Coolify does not know that the branch of the pull request is in this repository. A pull request from a fork could add host mounts to it'],
]);

test('the checkout read prints only regular files inside the repository', function () {
    $root = storage_path('framework/testing/pr-compose-'.uniqid());
    $outside = "{$root}-outside";
    File::ensureDirectoryExists($root);
    File::ensureDirectoryExists($outside);
    file_put_contents("{$root}/docker-compose.yml", BASE_BRANCH_COMPOSE);
    file_put_contents("{$outside}/docker-compose.yml", 'outside');
    symlink("{$outside}/docker-compose.yml", "{$root}/link.yml");
    symlink($outside, "{$root}/linked-dir");
    file_put_contents("{$root}/large.yml", str_repeat('a', Application::MAX_DOCKER_COMPOSE_SIZE_BYTES + 1));
    $read = fn (string $path): string => Process::run(['bash', '-c', (new ReflectionMethod(ApplicationDeploymentJob::class, 'pullRequestComposeFileReadCommand'))->invoke(null, "{$root}{$path}", $root)])->output();

    try {
        expect($read('/docker-compose.yml'))->toBe(BASE_BRANCH_COMPOSE)
            ->and($read('/missing.yml'))->toBe("__COOLIFY_COMPOSE_NOT_FOUND__\n")
            ->and($read('/link.yml'))->toBe("__COOLIFY_COMPOSE_SYMLINK__\n")
            ->and($read('/linked-dir/docker-compose.yml'))->toBe("__COOLIFY_COMPOSE_SYMLINK__\n")
            ->and($read('/large.yml'))->toBe("__COOLIFY_COMPOSE_TOO_LARGE__\n");
    } finally {
        File::deleteDirectory($root);
        File::deleteDirectory($outside);
    }
});
