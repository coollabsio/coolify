<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Linux limits one exec argument to 128 KiB (MAX_ARG_STRLEN). generateSshCommand() puts the whole
 * remote script in one `sh -c` argument, so file content must reach the server over SSH stdin.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    config(['app.maintenance.store' => 'array', 'constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);

    $this->processes = collect();
    Process::fake(function (PendingProcess $process) {
        $this->processes->push(['command' => $process->command, 'input' => $process->input]);

        return Process::result();
    });

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $labels = collect(range(1, 4000))
        ->map(fn (int $i) => "      - coolify.test.label{$i}=".str_repeat('x', 40))
        ->implode("\n");
    $this->compose = "services:\n  app:\n    image: nginx\n    labels:\n{$labels}\n";

    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose' => $this->compose,
    ]);
});

it('streams a large compose file over stdin instead of the command line', function (string $user, string $tee) {
    $this->server->update(['user' => $user]);
    $workdir = $this->service->workdir();
    expect(strlen($this->compose))->toBeGreaterThan(200 * 1024);

    $this->service->saveComposeConfigs();

    $composeWrite = $this->processes->firstWhere('input', $this->compose);
    expect($composeWrite)->not->toBeNull()
        ->and($composeWrite['command'])->toEndWith(escapeshellarg("{$tee} '{$workdir}/docker-compose.yml' > /dev/null"));

    $envWrite = $this->processes->first(fn (array $process) => $process['input'] === 'SERVICE_NAME_APP=app');
    expect($envWrite)->not->toBeNull()
        ->and($envWrite['command'])->toMatch('/'.preg_quote($tee, '/').' \S+\.env\.tmp/');

    $this->processes->each(fn (array $process) => expect(strlen($process['command']))->toBeLessThan(8 * 1024));
    expect($this->processes->last()['command'])->toMatch('/mv \S+\.env\.tmp '.preg_quote($workdir, '/').'\/\.env/');
})->with([
    'root' => ['root', 'tee'],
    'non-root' => ['cooluser', 'sudo tee'],
]);
