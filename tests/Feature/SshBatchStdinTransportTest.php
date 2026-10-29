<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ProcessStatus;
use App\Exceptions\DeploymentException;
use App\Models\ApplicationDeploymentQueue;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\User;
use App\Services\DatabaseStartCommandExecutor;
use App\Services\DockerRegistryLogins;
use App\Support\RemoteProcessCommand;
use App\Traits\ExecuteRemoteCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

/**
 * Remote command batches must reach ssh on stdin. A batch inside the argv of a local process
 * (for example a heredoc in `sh -c`) shows its secrets to every user of `ps` on the Coolify host.
 *
 * These tests run the real generated command. A stub `ssh` on PATH records its argv, the argv of
 * every ancestor process up to PHP, and its stdin, then runs the remote shell command locally.
 */
uses(RefreshDatabase::class);

const SSH_STDIN_SECRET = 'rr-n5-secret-Zq81xLp';

beforeEach(function () {
    config()->set('constants.ssh.mux_enabled', false);
    config()->set('constants.ssh.max_retries', 3);
    config()->set('constants.ssh.retry_base_delay', 0);
    config()->set('constants.ssh.retry_max_delay', 0);
    Server::flushIdentityMap();

    $this->stubDir = sys_get_temp_dir().'/ssh-stdin-stub-'.uniqid();
    File::ensureDirectoryExists($this->stubDir.'/bin');
    File::ensureDirectoryExists($this->stubDir.'/log');

    $log = $this->stubDir.'/log';
    $phpPid = getmypid();
    File::put($this->stubDir.'/bin/ssh', <<<BASH
#!/bin/bash
id="\$(date +%s%N).\$\$"
printf '%s\\0' "\$@" > "{$log}/argv.\$id"
pid=\$\$
while [ "\$pid" -gt 1 ] && [ "\$pid" != "{$phpPid}" ]; do
    tr '\\0' ' ' < /proc/\$pid/cmdline >> "{$log}/ancestors.\$id"
    echo >> "{$log}/ancestors.\$id"
    pid=\$(sed 's/.*) //' /proc/\$pid/stat | cut -d' ' -f2)
done
tee "{$log}/stdin.\$id" | sh -c "\${@: -1}"
BASH);
    File::put($this->stubDir.'/bin/sudo', "#!/bin/sh\nexec \"\$@\"\n");
    chmod($this->stubDir.'/bin/ssh', 0755);
    chmod($this->stubDir.'/bin/sudo', 0755);
    File::put($this->stubDir.'/bin/docker', "#!/bin/sh\n[ \"\$3\" = login ] && { cat > \"{$log}/docker-login.stdin\"; echo 'Login Succeeded'; }\nexit 0\n");
    chmod($this->stubDir.'/bin/docker', 0755);

    $this->originalPath = getenv('PATH');
    $path = $this->stubDir.'/bin:'.$this->originalPath;
    putenv("PATH={$path}");
    $_SERVER['PATH'] = $_ENV['PATH'] = $path;
});

afterEach(function () {
    putenv("PATH={$this->originalPath}");
    $_SERVER['PATH'] = $_ENV['PATH'] = $this->originalPath;
    File::deleteDirectory($this->stubDir);
    Server::flushIdentityMap();
});

function sshStdinStubServer(string $user = 'root'): Server
{
    $owner = User::factory()->create();
    $team = $owner->teams()->first();

    Storage::fake('ssh-keys');
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);

    return Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
        'user' => $user,
    ]);
}

/**
 * @return array{invocations: int, argv: string, stdin: string}
 */
function sshStdinStubRecords(string $stubDir): array
{
    $files = fn (string $prefix) => glob("{$stubDir}/log/{$prefix}.*") ?: [];
    $read = fn (string $prefix) => collect($files($prefix))->map(fn (string $file) => (string) file_get_contents($file))->implode("\n");

    return [
        'invocations' => count($files('argv')),
        'argv' => $read('argv')."\n".$read('ancestors'),
        'stdin' => $read('stdin'),
    ];
}

function sshStdinRemoteProcessActivity(Server $server, string $command): Activity
{
    return activity()
        ->withProperties([
            'server_uuid' => $server->uuid,
            ...RemoteProcessCommand::properties($command),
            'type' => 'inline',
            'status' => ProcessStatus::QUEUED->value,
            'team_id' => $server->team_id,
        ])
        ->event('inline')
        ->log('[]');
}

function sshStdinDeploymentRunner(Server $server): object
{
    $queue = ApplicationDeploymentQueue::create([
        'deployment_uuid' => 'ssh-stdin-deployment',
        'application_id' => 0,
        'server_id' => $server->id,
        'status' => 'in_progress',
    ]);

    return new class($server, $queue)
    {
        use ExecuteRemoteCommand;

        public Collection $saved_outputs;

        public function __construct(public Server $server, public ApplicationDeploymentQueue $application_deployment_queue)
        {
            $this->saved_outputs = collect();
        }
    };
}

it('runs an instant remote batch with its secret only on ssh stdin', function (string $user) {
    $server = sshStdinStubServer($user);

    $output = instant_remote_process([
        'export DB_PASSWORD='.SSH_STDIN_SECRET,
        'test -n "$DB_PASSWORD"',
        'echo "len=${#DB_PASSWORD} literal=\$HOME tick=`echo t`"',
    ], $server);

    $records = sshStdinStubRecords($this->stubDir);
    expect($output)->toBe('len='.strlen(SSH_STDIN_SECRET).' literal=$HOME tick=t')
        ->and($records['invocations'])->toBe(1)
        ->and($records['stdin'])->toContain(SSH_STDIN_SECRET)
        ->and($records['argv'])->toContain('bash -se')
        ->not->toContain(SSH_STDIN_SECRET);
    if ($user !== 'root') {
        expect($records['stdin'])->toContain('sudo test -n');
    }
})->with(['root' => 'root', 'non-root' => 'rr-deployer']);

it('does not retry a remote command that fails with exit code 1', function () {
    $server = sshStdinStubServer();

    expect(fn () => instant_remote_process(['echo '.SSH_STDIN_SECRET.' > /dev/null', 'echo failed >&2', 'exit 1', 'echo never'], $server))
        ->toThrow(RuntimeException::class, 'failed');

    $records = sshStdinStubRecords($this->stubDir);
    expect($records['invocations'])->toBe(1)
        ->and($records['argv'])->not->toContain(SSH_STDIN_SECRET);
});

it('retries the whole batch when ssh exits with the transport code 255', function () {
    $server = sshStdinStubServer();

    expect(fn () => instant_remote_process(['echo '.SSH_STDIN_SECRET.' > /dev/null', 'echo "Connection refused" >&2', 'exit 255'], $server))
        ->toThrow(RuntimeException::class);

    $records = sshStdinStubRecords($this->stubDir);
    expect($records['invocations'])->toBe(3)
        ->and($records['argv'])->not->toContain(SSH_STDIN_SECRET);
});

it('returns nothing for a failed batch with errors ignored', function () {
    $server = sshStdinStubServer();

    expect(instant_remote_process(['exit 3'], $server, throwError: false))->toBeNull();
});

it('runs batches larger than the 128 KiB argument limit and returns large output', function () {
    $server = sshStdinStubServer();
    $payload = str_repeat('a', 200 * 1024);

    $output = instant_remote_process([
        "PAYLOAD='{$payload}'",
        'echo "${#PAYLOAD}"',
        'head -c 3000000 /dev/zero | tr "\0" "b"',
    ], $server);

    expect(strlen($output))->toBe(strlen((string) (200 * 1024)) + 1 + 3000000)
        ->and($output)->toStartWith((200 * 1024)."\nbbb");
});

it('streams remote process output into the activity log with the secret only on stdin', function (string $user) {
    $server = sshStdinStubServer($user);
    $activity = sshStdinRemoteProcessActivity($server, implode("\n", [
        'export TOKEN='.SSH_STDIN_SECRET,
        'echo first',
        'sleep 0.5',
        'echo second',
    ]));

    $result = (new RunRemoteProcess($activity))();

    $entries = collect(json_decode($activity->fresh()->description, true));
    $records = sshStdinStubRecords($this->stubDir);
    expect($result->exitCode())->toBe(0)
        ->and($entries->pluck('output')->map(fn ($output) => trim($output))->all())->toBe(['first', 'second'])
        ->and($activity->fresh()->getExtraProperty('status'))->toBe(ProcessStatus::FINISHED->value)
        ->and($records['stdin'])->toContain(SSH_STDIN_SECRET)
        ->and($records['argv'])->not->toContain(SSH_STDIN_SECRET);
})->with(['root' => 'root', 'non-root' => 'rr-deployer']);

it('keeps the exit code of a failed remote process and honours ignore_errors', function () {
    $server = sshStdinStubServer();

    $failing = sshStdinRemoteProcessActivity($server, "echo broken >&2\nexit 7");
    expect(fn () => (new RunRemoteProcess($failing))())->toThrow(RuntimeException::class, 'broken');
    expect($failing->fresh()->getExtraProperty('exitCode'))->toBe(7)
        ->and($failing->fresh()->getExtraProperty('status'))->toBe(ProcessStatus::ERROR->value);

    $ignored = sshStdinRemoteProcessActivity($server, 'exit 7');
    expect((new RunRemoteProcess($ignored, ignore_errors: true))()->exitCode())->toBe(7);
});

it('runs deployment commands with the secret only on stdin and streams their output', function (string $user) {
    $server = sshStdinStubServer($user);
    $runner = sshStdinDeploymentRunner($server);

    $runner->execute_remote_command(
        ['command' => 'test -n '.SSH_STDIN_SECRET.' && echo deploy-42', 'hidden' => true],
        ['command' => 'cat', 'input' => "from-input\n"],
        ['command' => 'false', 'ignore_errors' => true],
    );

    $logs = collect(json_decode($runner->application_deployment_queue->fresh()->logs, true))->pluck('output');
    $records = sshStdinStubRecords($this->stubDir);
    expect($logs->all())->toContain('deploy-42', 'from-input')
        ->and($records['argv'])->not->toContain(SSH_STDIN_SECRET)
        ->and($records['stdin'])->toContain(SSH_STDIN_SECRET);
})->with(['root' => 'root', 'non-root' => 'rr-deployer']);

it('fails a deployment command with its remote exit code', function () {
    $server = sshStdinStubServer();
    $runner = sshStdinDeploymentRunner($server);

    expect(fn () => $runner->execute_remote_command(['command' => 'echo nope >&2; exit 4']))
        ->toThrow(DeploymentException::class, 'exit code 4');
    expect(sshStdinStubRecords($this->stubDir)['invocations'])->toBe(1);
});

it('starts a database with its password only on ssh stdin', function (string $user) {
    $server = sshStdinStubServer($user);
    $database = new class extends Model {};
    $database->setRelation('destination', (object) ['server' => $server]);
    $activity = activity()->withProperties(['status' => ProcessStatus::QUEUED->value])->event('inline')->log('[]');

    app(DatabaseStartCommandExecutor::class)->execute([
        'export POSTGRES_PASSWORD='.SSH_STDIN_SECRET,
        'echo "started ${#POSTGRES_PASSWORD}"',
    ], $database, $activity);

    $records = sshStdinStubRecords($this->stubDir);
    expect($activity->fresh()->getExtraProperty('status'))->toBe(ProcessStatus::FINISHED->value)
        ->and(collect(json_decode($activity->fresh()->description, true))->pluck('output')->implode(''))->toContain('started '.strlen(SSH_STDIN_SECRET))
        ->and($records['stdin'])->toContain(SSH_STDIN_SECRET)
        ->and($records['argv'])->not->toContain(SSH_STDIN_SECRET);
})->with(['root' => 'root', 'non-root' => 'rr-deployer']);

it('logs in to a registry with the password only on ssh stdin', function (string $user) {
    $server = sshStdinStubServer($user);

    DockerRegistryLogins::login($server, 'registry.example.com', 'deployer', SSH_STDIN_SECRET);

    $records = sshStdinStubRecords($this->stubDir);
    expect(file_get_contents($this->stubDir.'/log/docker-login.stdin'))->toBe(SSH_STDIN_SECRET)
        ->and($records['argv'])->not->toContain(SSH_STDIN_SECRET)
        ->not->toContain(base64_encode(SSH_STDIN_SECRET));
})->with(['root' => 'root', 'non-root' => 'rr-deployer']);
