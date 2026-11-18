<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * With "Preserve Repository", a deployment copies its checkout to the server. A preview copied the checkout of
 * its pull request into the folder of the application, so it replaced the files and the .env of production.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    Bus::fake();
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'compose_parsing_version' => '5',
    ]);
    $this->application->settings->update(['is_preserve_repository_enabled' => true]);
    $this->workdir = $this->application->workdir();
    foreach (['data/app.conf' => true, 'shared.conf' => false] as $path => $hasSuffix) {
        LocalFileVolume::create([
            'fs_path' => "{$this->workdir}/{$path}",
            'mount_path' => '/etc/'.basename($path),
            'is_directory' => false,
            'is_based_on_git' => true,
            'is_preview_suffix_enabled' => $hasSuffix,
            'resource_id' => $this->application->id,
            'resource_type' => $this->application->getMorphClass(),
        ]);
    }
    LocalFileVolume::create([
        'fs_path' => '/etc/host.conf',
        'mount_path' => '/etc/host.conf',
        'is_host_file' => true,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $this->commands = collect();
    Process::fake(function (PendingProcess $process) {
        $this->commands->push(remoteProcessScript($process));

        return Process::result(output: 'OK');
    });
});

function writePreviewDeploymentConfigurations(Application $application, Server $server, int $pullRequestId): void
{
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $application->id,
        'deployment_uuid' => 'preview-folder-'.$pullRequestId,
        'status' => 'in_progress',
        'server_id' => $server->id,
        'destination_id' => $application->destination_id,
        'commit' => 'HEAD',
        'pull_request_id' => $pullRequestId,
    ]);
    if ($pullRequestId !== 0) {
        ApplicationPreview::create([
            'application_id' => $application->id,
            'pull_request_id' => $pullRequestId,
            'pull_request_html_url' => "https://example.com/pull/{$pullRequestId}",
        ]);
    }
    $job = new ApplicationDeploymentJob($deployment->id);
    (new ReflectionProperty($job, 'generated_docker_compose'))->setValue($job, "services: {}\n");
    (new ReflectionMethod($job, 'write_deployment_configurations'))->invoke($job);
}

test('a preview writes its checkout, Compose file and .env to its own folder', function () {
    writePreviewDeploymentConfigurations($this->application, $this->server, 3);

    $all = $this->commands->implode("\n");
    expect($all)->toContain("docker cp preview-folder-3:/artifacts/preview-folder-3/. {$this->workdir}-pr-3")
        ->and($all)->toContain("tee {$this->workdir}-pr-3/docker-compose-pr-3.yaml")
        ->and($all)->not->toContain(". {$this->workdir}\n")
        ->and($all)->not->toMatch('#'.preg_quote($this->workdir, '#').'/(\.env|docker-compose|README)#');
});

test('a preview copies the repository files of its file storages to their preview mount paths', function () {
    writePreviewDeploymentConfigurations($this->application, $this->server, 3);

    $copies = $this->commands->filter(fn (string $command) => str_contains($command, 'readlink -f "$src"'))->values();
    expect($copies)->toHaveCount(1)
        ->and($copies[0])->toContain(' sh '.escapeshellarg("{$this->workdir}-pr-3").' '.escapeshellarg("{$this->workdir}-pr-3/data/app.conf").' '.escapeshellarg("{$this->workdir}/data/app.conf-pr-3"));
});

test('production still copies its checkout to the folder of the application', function () {
    writePreviewDeploymentConfigurations($this->application, $this->server, 0);

    $all = $this->commands->implode("\n");
    expect($all)->toContain("docker cp preview-folder-0:/artifacts/preview-folder-0/. {$this->workdir}\n")
        ->and($all)->toContain("tee {$this->workdir}/docker-compose.yaml")
        ->and($all)->not->toContain('readlink -f "$src"');
});

/**
 * Runs the copy script with a local shell on a temporary checkout ($base) and mount folder ($mounts).
 *
 * @param  Closure(string $base, string $mounts): void  $arrange
 */
function runPreviewCopyScript(Closure $arrange, string $source, string $destination): array
{
    $root = sys_get_temp_dir().'/preview-copy-'.bin2hex(random_bytes(4));
    $base = "{$root}/app-pr-1";
    $mounts = "{$root}/app";
    mkdir($base, recursive: true);
    mkdir($mounts, recursive: true);
    $arrange($base, $mounts);
    $script = (new ReflectionClassConstant(ApplicationDeploymentJob::class, 'PREVIEW_REPOSITORY_FILE_COPY_SCRIPT'))->getValue();
    exec('sh -c '.escapeshellarg($script).' sh '.escapeshellarg($base).' '.escapeshellarg("{$base}/{$source}").' '.escapeshellarg("{$mounts}/{$destination}").' 2>&1', $output, $exitCode);

    return [$root, $mounts, $exitCode];
}

test('the copy script copies a file of the checkout to its mount path', function () {
    [$root, $mounts, $exitCode] = runPreviewCopyScript(function (string $base) {
        mkdir("{$base}/data");
        file_put_contents("{$base}/data/app.conf", 'listen 9090;');
    }, 'data/app.conf', 'data/app.conf-pr-1');

    expect($exitCode)->toBe(0)
        ->and(file_get_contents("{$mounts}/data/app.conf-pr-1"))->toBe('listen 9090;');
    exec('rm -rf '.escapeshellarg($root));
});

test('the copy script replaces the empty folder that Docker created at the path of a file', function () {
    [$root, $mounts] = runPreviewCopyScript(function (string $base, string $mounts) {
        file_put_contents("{$base}/app.conf", 'listen 9090;');
        mkdir("{$mounts}/app.conf-pr-1");
    }, 'app.conf', 'app.conf-pr-1');

    expect(is_file("{$mounts}/app.conf-pr-1"))->toBeTrue();
    exec('rm -rf '.escapeshellarg($root));
});

test('the copy script merges a folder of the checkout into the mount folder and keeps its data', function () {
    [$root, $mounts] = runPreviewCopyScript(function (string $base, string $mounts) {
        mkdir("{$base}/config");
        file_put_contents("{$base}/config/app.conf", 'new');
        mkdir("{$mounts}/config-pr-1");
        file_put_contents("{$mounts}/config-pr-1/data.db", 'data');
    }, 'config', 'config-pr-1');

    expect(file_get_contents("{$mounts}/config-pr-1/app.conf"))->toBe('new')
        ->and(file_get_contents("{$mounts}/config-pr-1/data.db"))->toBe('data');
    exec('rm -rf '.escapeshellarg($root));
});

test('the copy script does not copy host files through symbolic links of the pull request', function (Closure $arrange, string $source) {
    [$root, $mounts] = runPreviewCopyScript($arrange, $source, 'app.conf-pr-1');

    expect(file_exists("{$mounts}/app.conf-pr-1"))->toBeFalse();
    exec('rm -rf '.escapeshellarg($root));
})->with([
    'the file is a link' => [fn (string $base) => symlink('/etc/hostname', "{$base}/app.conf"), 'app.conf'],
    'a parent folder is a link' => [fn (string $base) => symlink('/etc', "{$base}/data"), 'data/hostname'],
]);

test('the copy script replaces a link at the mount path instead of writing through it', function () {
    $outside = tempnam(sys_get_temp_dir(), 'outside');
    file_put_contents($outside, 'host');
    [$root, $mounts] = runPreviewCopyScript(function (string $base, string $mounts) use ($outside) {
        file_put_contents("{$base}/app.conf", 'listen 9090;');
        symlink($outside, "{$mounts}/app.conf-pr-1");
    }, 'app.conf', 'app.conf-pr-1');

    expect(file_get_contents($outside))->toBe('host')
        ->and(is_link("{$mounts}/app.conf-pr-1"))->toBeFalse()
        ->and(file_get_contents("{$mounts}/app.conf-pr-1"))->toBe('listen 9090;');
    exec('rm -rf '.escapeshellarg($root).' '.escapeshellarg($outside));
});

test('the non-root sudo parser keeps the copy script intact', function () {
    $this->server->update(['user' => 'deploy']);
    $command = 'sh -c '.escapeshellarg((new ReflectionClassConstant(ApplicationDeploymentJob::class, 'PREVIEW_REPOSITORY_FILE_COPY_SCRIPT'))->getValue()).' sh a b c';

    expect(parseCommandsByLineForSudo(collect([$command]), $this->server->fresh()))->toBe(['sudo bash -c '.escapeshellarg($command)]);
});
