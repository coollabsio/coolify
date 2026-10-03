<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

uses(RefreshDatabase::class);

/**
 * Process arguments are readable by every user on the server (/proc/<pid>/cmdline). The Sentinel
 * token must reach curl over stdin, never as an argument of docker, the container shell, or curl.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    config(['constants.ssh.mux_enabled' => false, 'constants.ssh.max_retries' => 1]);
    Process::fake(fn () => Process::result(output: '{"requests":1}'));

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);

    $this->client = new class($this->server) extends SentinelTrafficClient
    {
        public function fetch(string $url): string
        {
            return $this->remoteFetch($url);
        }

        /**
         * @param  array<int, string>  $urls
         */
        public function batch(array $urls): string
        {
            return $this->batchRemoteFetch($urls);
        }
    };

    $this->bin = sys_get_temp_dir().'/sentinel-token-stdin-'.uniqid();
    File::makeDirectory($this->bin);
    $log = "{$this->bin}/calls.log";
    // Each fake logs its arguments; curl also logs what it reads on stdin (the -H @- headers).
    File::put("{$this->bin}/sudo", "#!/bin/sh\necho \"argv: sudo \$*\" >> {$log}\nexec \"\$@\"\n");
    // `docker exec [options] <container> <command...>` runs the command here.
    File::put("{$this->bin}/docker", "#!/bin/sh\necho \"argv: docker \$*\" >> {$log}\nshift\nwhile [ \"\${1#-}\" != \"\$1\" ]; do shift; done\nshift\nexec \"\$@\"\n");
    File::put("{$this->bin}/curl", "#!/bin/sh\necho \"argv: curl \$*\" >> {$log}\necho \"stdin: \$(cat)\" >> {$log}\nprintf '{}'\n");
    File::put("{$this->bin}/sh", "#!/bin/sh\necho \"argv: sh \$*\" >> {$log}\nexec /bin/sh \"\$@\"\n");
    foreach (['sudo', 'docker', 'curl', 'sh'] as $fake) {
        chmod("{$this->bin}/{$fake}", 0755);
    }
});

afterEach(function () {
    File::deleteDirectory($this->bin);
});

/**
 * Runs the script that Coolify sent over SSH in a real shell with the fake binaries first on PATH.
 *
 * @return array{argv: array<int, string>, stdin: array<int, string>}
 */
function runSentSentinelScriptWithFakes(string $bin): array
{
    $script = null;
    Process::assertRan(function ($process) use (&$script) {
        // generateSshCommand sends the script as the body of a heredoc: first line opens it, last line closes it.
        $lines = explode("\n", $process->command);
        $script = implode("\n", array_slice($lines, 1, -1));

        return true;
    });

    $run = new SymfonyProcess(['/bin/sh', '-s'], env: ['PATH' => "{$bin}:/usr/bin:/bin"]);
    $run->setInput($script."\n");
    $run->mustRun();

    $log = collect(file("{$bin}/calls.log", FILE_IGNORE_NEW_LINES));

    return [
        'argv' => $log->filter(fn (string $line) => str_starts_with($line, 'argv: '))->values()->all(),
        'stdin' => $log->filter(fn (string $line) => str_starts_with($line, 'stdin: '))->values()->all(),
    ];
}

it('sends the sentinel token to curl over stdin for a single fetch', function (string $user) {
    $this->server->update(['user' => $user]);
    $url = 'http://localhost:8888/api/traffic/overview?from=2026-08-01T00:00:00Z&to=2026-08-02T00:00:00Z';

    $this->client->fetch($url);
    $token = $this->server->settings->fresh()->sentinel_token;
    $calls = runSentSentinelScriptWithFakes($this->bin);

    expect($calls['stdin'])->toBe(["stdin: Authorization: Bearer {$token}"])
        ->and(collect($calls['argv'])->filter(fn (string $line) => str_contains($line, $token)))->toBeEmpty()
        ->and(collect($calls['argv'])->filter(fn (string $line) => str_starts_with($line, 'argv: curl ')))
        ->toHaveCount(1)
        ->each->toContain($url);
})->with(['root', 'non-root' => 'cooluser']);

it('sends the sentinel token to every curl over stdin for a batched fetch', function (string $user) {
    $this->server->update(['user' => $user]);
    $urls = [
        'http://localhost:8888/api/traffic/overview?from=F&to=T',
        'http://localhost:8888/api/traffic/apps',
    ];

    $this->client->batch($urls);
    $token = $this->server->settings->fresh()->sentinel_token;
    $calls = runSentSentinelScriptWithFakes($this->bin);

    expect($calls['stdin'])->toBe([
        "stdin: Authorization: Bearer {$token}",
        "stdin: Authorization: Bearer {$token}",
    ])
        ->and(collect($calls['argv'])->filter(fn (string $line) => str_contains($line, $token)))->toBeEmpty();
})->with(['root', 'non-root' => 'cooluser']);
