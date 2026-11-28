<?php

use App\Jobs\DockerCleanupJob;
use App\Models\DockerCleanupExecution;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\DeployTriggers\Git\SshMirrorGit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    $fixture = deployTriggerFixture();
    $fixture['server']->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $fixture['team']->id])->id]);
    Server::flushIdentityMap();
    $this->server = $fixture['server']->fresh();
    $this->scripts = collect();
});

function fakeCleanupProcesses($scripts, ?Closure $prune = null): void
{
    Process::fake(function (PendingProcess $process) use ($scripts, $prune) {
        $script = remoteProcessScript($process);
        $scripts->push($script);
        if ($prune && str_contains($script, 'coolify-git-mirrors:/mirrors')) {
            return $prune();
        }

        return Process::result(output: '');
    });
}

it('prunes git mirrors during docker cleanup', function () {
    fakeCleanupProcesses($this->scripts);

    (new DockerCleanupJob($this->server))->handle();

    $prune = $this->scripts->first(fn (string $script) => str_contains($script, '-v coolify-git-mirrors:/mirrors'));
    expect($prune)->not->toBeNull()
        ->and($prune)->toContain('-mtime +14')
        ->and($prune)->toContain('docker volume inspect coolify-git-mirrors')
        ->and($prune)->toContain('-v coolify-git-mirrors:/mirrors');
});

it('removes only mirrors that are unused for 14 days and not locked by an evaluation', function () {
    $root = sys_get_temp_dir().'/mirror-prune-'.bin2hex(random_bytes(6));
    foreach (['old', 'busy', 'fresh'] as $name) {
        File::ensureDirectoryExists("{$root}/{$name}.git/objects");
        File::put("{$root}/{$name}.git.lock", '');
    }
    Process::run(['touch', '-d', '20 days ago', "{$root}/old.git", "{$root}/busy.git", "{$root}/old.git.lock", "{$root}/busy.git.lock"])->throw();
    $holder = Process::start(['flock', "{$root}/busy.git.lock", 'sleep', '10']);
    usleep(300000);

    $image = getenv('DEPLOY_TRIGGERS_HELPER_IMAGE');
    $prune = $image
        ? Process::run(['docker', 'run', '--rm', '-v', "{$root}:/mirrors", '--entrypoint', 'sh', $image, '-c', SshMirrorGit::pruneScript('/mirrors')])
        : Process::run(['sh', '-c', SshMirrorGit::pruneScript($root)]);
    $holder->signal(SIGTERM);

    expect($prune->successful())->toBeTrue()
        ->and(File::exists("{$root}/old.git"))->toBeFalse()
        ->and(File::exists("{$root}/busy.git"))->toBeTrue()
        ->and(File::exists("{$root}/busy.git.lock"))->toBeTrue()
        ->and(File::exists("{$root}/fresh.git"))->toBeTrue();
    File::deleteDirectory($root);
});

it('prunes mirrors on a server with a non-root user without changing the script', function () {
    $this->server->update(['user' => 'deploy']);
    Server::flushIdentityMap();
    $this->server = $this->server->fresh();
    fakeCleanupProcesses($this->scripts);

    (new DockerCleanupJob($this->server))->handle();

    $prune = $this->scripts->first(fn (string $script) => str_contains($script, '-v coolify-git-mirrors:/mirrors'));
    expect($prune)->toContain('sudo docker volume inspect coolify-git-mirrors')
        ->and($prune)->toContain("-c '".SshMirrorGit::pruneScript('/mirrors')."'");
});

it('keeps the cleanup successful when pruning fails', function () {
    fakeCleanupProcesses($this->scripts, fn () => Process::result(errorOutput: 'not found', exitCode: 127));

    (new DockerCleanupJob($this->server))->handle();

    expect(DockerCleanupExecution::query()->where('server_id', $this->server->id)->sole()->status)->toBe('success');
});
