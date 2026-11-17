<?php

use App\Actions\Server\StartSentinel;
use App\Events\SentinelRestarted;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Event::fake([SentinelRestarted::class]);
    InstanceSettings::forceCreate(['id' => 0]);
    config(['constants.ssh.mux_enabled' => false]);

    $this->remoteBatches = [];
    Process::fake(function (PendingProcess $process) {
        $this->remoteBatches[] = (string) $process->input;

        return Process::result(output: '');
    });

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings->update(['sentinel_custom_url' => 'https://coolify.example.com']);

    $this->workDirectory = sys_get_temp_dir().'/sentinel-token-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->workDirectory.'/bin');
});

afterEach(function () {
    File::deleteDirectory($this->workDirectory);
});

/**
 * The `sh -ec '...'` command that StartSentinel sends to the server. It spans several lines,
 * and the next command of the batch is the chown of the Sentinel directory.
 */
function sentinelTokenFileScript(array $remoteBatches): string
{
    $batch = collect($remoteBatches)->first(fn (string $input): bool => str_contains($input, 'docker run -d'));
    expect($batch)->not->toBeNull();
    preg_match('/^sh -ec \'.*?(?=\nchown -R 9999:root )/ms', $batch, $match);
    expect($match)->toHaveCount(1);

    return $match[0];
}

it('removes the env file with the Sentinel token after docker run succeeds or fails', function (int $dockerExitCode) {
    StartSentinel::run($this->server, latestVersion: '1.0.1');

    $envFile = $this->workDirectory.'/sentinel.env';
    $script = str_replace('/data/coolify/sentinel.env', $envFile, sentinelTokenFileScript($this->remoteBatches));

    // A fake docker binary records the env file that `docker run --env-file` gets, and its mode.
    file_put_contents($this->workDirectory.'/bin/docker', <<<'SH'
        #!/bin/sh
        while [ $# -gt 0 ]; do
            if [ "$1" = "--env-file" ]; then
                cat "$2" > "$CAPTURE"
                stat -c %a "$2" > "$CAPTURE.mode"
            fi
            shift
        done
        exit "$DOCKER_EXIT"
        SH);
    chmod($this->workDirectory.'/bin/docker', 0755);

    $capture = $this->workDirectory.'/captured.env';
    $process = new SymfonyProcess(['bash', '-c', $script], $this->workDirectory, [
        'PATH' => $this->workDirectory.'/bin:'.getenv('PATH'),
        'CAPTURE' => $capture,
        'DOCKER_EXIT' => (string) $dockerExitCode,
    ]);
    $process->run();

    $token = $this->server->settings->fresh()->sentinel_token;

    expect($process->getExitCode())->toBe($dockerExitCode)
        ->and(file_get_contents($capture))->toContain("TOKEN={$token}\n")
        ->and(trim(file_get_contents($capture.'.mode')))->toBe('600')
        ->and(file_exists($envFile))->toBeFalse();
})->with([
    'docker run succeeds' => [0],
    'docker run fails' => [1],
]);
