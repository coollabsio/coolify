<?php

use App\Actions\Node\InspectNodeHost;
use App\Actions\Node\PrepareNodeHost;
use App\Actions\Node\ValidateNode;
use App\Actions\Node\ValidateNodeCallback;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\PrivateKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process as ShellProcess;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Storage::fake('ssh-keys');
    $privateKey = PrivateKey::factory()->create();
    Storage::disk('ssh-keys')->put("ssh_key@{$privateKey->uuid}", $privateKey->private_key);
    $this->privateKey = $privateKey;

    $this->sandbox = sys_get_temp_dir().'/coolify-node-sudo-'.bin2hex(random_bytes(6));
    $this->bin = "{$this->sandbox}/bin";
    $this->log = "{$this->sandbox}/commands.log";
    File::ensureDirectoryExists($this->bin);
    File::put($this->log, '');

    foreach (['bash', 'base64', 'hostname', 'uname', 'getconf', 'awk', 'cut', 'cat'] as $tool) {
        symlink(trim((string) shell_exec('command -v '.escapeshellarg($tool))), "{$this->bin}/{$tool}");
    }
    $sudo = "#!/bin/bash\nprintf 'sudo %s\\n' \"\$1\" >> ".escapeshellarg($this->log)."\nexport ELEVATED=1\nexec \"\$@\"\n";
    File::put("{$this->bin}/sudo", $sudo);
    chmod("{$this->bin}/sudo", 0755);
    foreach (['apt-get', 'systemctl', 'ln', 'podman', 'curl'] as $tool) {
        $stub = "#!/bin/bash\nprintf '%s elevated=%s\\n' ".escapeshellarg($tool).' "${ELEVATED:-0}" >> '.escapeshellarg($this->log)."\n";
        if ($tool === 'podman') {
            $stub .= "[ \"\$1\" = info ] && echo '{}'\n";
        }
        File::put("{$this->bin}/{$tool}", $stub."exit 0\n");
        chmod("{$this->bin}/{$tool}", 0755);
    }

    $this->sentScripts = [];
    Process::fake(function (PendingProcess $process) {
        $lines = explode(PHP_EOL, (string) $process->command);
        if (count($lines) < 3) {
            return Process::result();
        }
        $body = implode(PHP_EOL, array_slice($lines, 1, -1));
        $this->sentScripts[] = $body;

        $shell = new ShellProcess(['bash', '-se'], $this->sandbox, ['PATH' => $this->bin, 'ELEVATED' => false], $body, 30);
        $shell->run();

        return Process::result(output: $shell->getOutput(), errorOutput: $shell->getErrorOutput(), exitCode: $shell->getExitCode());
    });
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

function nodeWithSshUser(string $user, PrivateKey $privateKey): Node
{
    return Node::factory()->create([
        'team_id' => $privateKey->team_id,
        'private_key_id' => $privateKey->id,
        'user' => $user,
    ]);
}

/** @return list<string> */
function loggedHostCommands(string $log): array
{
    return array_values(array_filter(explode("\n", (string) file_get_contents($log))));
}

it('inspects the host inside one elevated shell for a non-root ssh user', function () {
    $facts = InspectNodeHost::run(nodeWithSshUser('coolify', $this->privateKey));

    expect($facts['package_manager'])->toBe('apt-get')
        ->and($facts['podman_installed'])->toBeTrue()
        ->and($facts['os'])->not->toBeEmpty()
        ->and(loggedHostCommands($this->log))->toContain('sudo bash')
        ->and(loggedHostCommands($this->log))->not->toContain('apt-get elevated=0');
});

it('prepares the host with every package and service command elevated for a non-root ssh user', function () {
    try {
        PrepareNodeHost::run(nodeWithSshUser('coolify', $this->privateKey));
    } catch (Throwable) {
        // test -S /var/run/docker.sock depends on the host running the test.
    }

    $commands = loggedHostCommands($this->log);
    expect($commands[0] ?? null)->toBe('sudo bash')
        ->and($commands)->toContain('apt-get elevated=1')
        ->and($commands)->toContain('systemctl elevated=1')
        ->and($commands)->toContain('ln elevated=1')
        ->and(collect($commands)->filter(fn (string $line): bool => str_ends_with($line, 'elevated=0')))->toBeEmpty();
});

it('validates the callback url inside one elevated shell for a non-root ssh user', function () {
    ValidateNodeCallback::run(nodeWithSshUser('coolify', $this->privateKey), 'https://coolify.example.com');

    expect(loggedHostCommands($this->log))->toBe(['sudo bash', 'curl elevated=1']);
});

it('validates podman with root privileges for a non-root ssh user', function () {
    ValidateNode::run(nodeWithSshUser('coolify', $this->privateKey));

    $commands = loggedHostCommands($this->log);
    expect($commands[0] ?? null)->toBe('sudo bash')
        ->and($commands)->toContain('systemctl elevated=1')
        ->and(collect($commands)->filter(fn (string $line): bool => str_ends_with($line, 'elevated=0')))->toBeEmpty();
});

it('sends the raw scripts unchanged for a root ssh user', function () {
    $node = nodeWithSshUser('root', $this->privateKey);

    InspectNodeHost::run($node);
    ValidateNodeCallback::run($node, 'https://coolify.example.com');
    ValidateNode::run($node);
    try {
        PrepareNodeHost::run($node);
    } catch (Throwable) {
        // test -S /var/run/docker.sock depends on the host running the test.
    }

    expect($this->sentScripts[1])->toBe(ValidateNodeCallback::validationScript('https://coolify.example.com'))
        ->and($this->sentScripts[2])->toBe(ValidateNode::PODMAN_VALIDATION_COMMAND)
        ->and($this->sentScripts[3])->toBe(PrepareNodeHost::installationScript())
        ->and($this->sentScripts[0])->toContain('printf \'hostname=%s\n\' "$(hostname)"')
        ->and(loggedHostCommands($this->log))->not->toContain('sudo bash');
});
