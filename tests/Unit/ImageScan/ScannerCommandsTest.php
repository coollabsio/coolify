<?php

use App\Models\Server;
use App\Services\ImageScan\ScannerCommands;
use Tests\TestCase;

uses(TestCase::class);

const SCANNER_TEST_RUN_ID = 'run1234abcd';
const SCANNER_TEST_HEX = 'aaaabbbbccccddddeeeeffff0000111122223333444455556666777788889999';

beforeEach(function () {
    $this->server = Mockery::mock(Server::class)->makePartial();
    $this->server->shouldReceive('getAttribute')->with('user')->andReturn('ubuntu');
    $this->server->shouldReceive('setAttribute')->andReturnSelf();
    $this->server->user = 'ubuntu';
    $this->commands = new ScannerCommands($this->server, SCANNER_TEST_RUN_ID);
});

afterEach(function () {
    Mockery::close();
});

/**
 * @return array<string, list<string>>
 */
function scannerCommandLists(ScannerCommands $commands): array
{
    $auth = base64_encode(json_encode(['auths' => ['ghcr.io' => ['auth' => base64_encode('user:token')]]]));

    return [
        'prepare' => $commands->prepare(),
        'dbUpdate' => $commands->dbUpdate(null),
        'dbUpdate with url' => $commands->dbUpdate('https://mirror.example.com/grype/databases'),
        'dbStatus' => $commands->dbStatus(),
        'inspect' => $commands->inspect('localhost:5000/team/app:1.2'),
        'diskFree' => $commands->diskFree(),
        'sbomFromLocal' => $commands->sbomFromLocal('nginx', SCANNER_TEST_HEX),
        'sbomFromRegistry' => $commands->sbomFromRegistry('ghcr.io/a/b@sha256:'.str_repeat('ab', 32), 'linux/amd64', 'cand-'.SCANNER_TEST_HEX, null),
        'sbomFromRegistry with auth' => $commands->sbomFromRegistry('ghcr.io/a/b:1', 'linux/arm64/v8', 'cand-1', $auth),
        'grype' => $commands->grype(SCANNER_TEST_HEX),
        'packages' => $commands->packages(SCANNER_TEST_HEX),
        'tags' => $commands->tags('nginx', null),
        'tags with auth' => $commands->tags('ghcr.io/a/b', $auth),
        'digest' => $commands->digest('nginx:1.27', null, null),
        'digest with platform and auth' => $commands->digest('ghcr.io/a/b:1', 'linux/amd64', $auth),
        'cleanup' => $commands->cleanup(),
        'deleteScannerData' => $commands->deleteScannerData(),
    ];
}

/**
 * Unwraps `sh -c '<script>'` lines so assertions read the script as the shell runs it.
 *
 * @param  list<string>  $commands
 * @return list<string>
 */
function scannerUnwrapped(array $commands): array
{
    return array_map(function (string $line): string {
        if (! str_starts_with($line, "sh -c '")) {
            return $line;
        }

        return str_replace("'\\''", "'", substr($line, 7, -1));
    }, $commands);
}

/**
 * @param  list<string>  $commands
 * @return list<string>
 */
function scannerDockerRuns(array $commands): array
{
    return array_values(array_filter(scannerUnwrapped($commands), fn (string $line) => str_contains($line, 'docker run')));
}

it('starts every command list by removing a leftover scan container', function () {
    foreach (scannerCommandLists($this->commands) as $name => $commands) {
        expect($commands[0])->toBe('docker rm -f coolify-scan-'.SCANNER_TEST_RUN_ID.' >/dev/null 2>&1 || true', $name);
    }
});

it('runs every scanner container with each hardening flag exactly once', function () {
    $flags = ['--rm ', '--name coolify-scan-'.SCANNER_TEST_RUN_ID.' ', '--cap-drop ALL ', '--security-opt no-new-privileges ', '--read-only ',
        '--pids-limit 256 ', '--user 65534:65534 ', '--cpus 1 ', '--memory 1g ', '--memory-swap 1g ',
        "-e 'SYFT_CHECK_FOR_APP_UPDATE=false' ", "-e 'GRYPE_CHECK_FOR_APP_UPDATE=false' ", "-e 'XDG_CACHE_HOME=/scratch/cache' "];
    $runs = 0;

    foreach (scannerCommandLists($this->commands) as $name => $commands) {
        foreach (scannerDockerRuns($commands) as $line) {
            $runs++;
            expect($line)->toMatch('/timeout -k 10 \d+ docker run /');
            foreach ($flags as $flag) {
                expect(substr_count($line, $flag))->toBe(1, "{$name}: {$flag}");
            }
        }
    }

    expect($runs)->toBe(12);
});

it('cuts the network only for runs that do not need a registry or the database download', function () {
    $lists = scannerCommandLists($this->commands);

    foreach (['dbStatus', 'sbomFromLocal', 'grype', 'packages'] as $name) {
        expect(scannerDockerRuns($lists[$name]))->each->toContain('--network none ');
    }
    foreach (['dbUpdate', 'sbomFromRegistry', 'tags', 'digest'] as $name) {
        expect(scannerDockerRuns($lists[$name]))->each->not->toContain('--network');
    }
});

it('gives the work directory to the scanner user before a container uses it', function () {
    $chown = "chown -R 65534:65534 '/data/coolify/image-scans/tmp-".SCANNER_TEST_RUN_ID."'";

    foreach (scannerCommandLists($this->commands) as $name => $commands) {
        $run = collect($commands)->search(fn (string $line) => str_contains($line, 'docker run'));
        if ($run === false) {
            continue;
        }

        expect(array_search($chown, $commands, true))->toBeInt($name)->toBeLessThan($run, $name);
    }
});

it('never gives a scanner the Docker socket', function () {
    foreach (scannerCommandLists($this->commands) as $commands) {
        expect(implode("\n", $commands))->not->toContain('docker.sock');
    }
});

it('pins scanner images by digest', function () {
    $all = implode("\n", array_merge(...array_values(scannerCommandLists($this->commands))));

    expect($all)->toContain("'anchore/syft@sha256:4cc92b64ca74ffca4c94d4c8fcc01f217731375300be14d0b6eeb2e82eaa110a'")
        ->toContain("'anchore/grype@sha256:621d56d1d68106b787022c81546f7e0a36202a25d1239f0c20195fab8c16f7ea'")
        ->toContain("'regclient/regctl@sha256:45b57933c2b67bb1e280b4baaa220ef1ab212c81268ab19589fa1e83719c7c82'")
        ->not->toMatch('/(syft|grype|regctl):latest/');
});

it('refuses to build commands with a scanner image digest that is not pinned', function (string $digest) {
    config()->set('constants.image_scan.images.grype.digest', $digest);

    (new ScannerCommands($this->server, SCANNER_TEST_RUN_ID))->grype(SCANNER_TEST_HEX);
})->with(['latest', 'sha256:abc', 'sha256:'.str_repeat('A', 64), 'sha256:'.str_repeat('a', 64)."\n"])->throws(InvalidArgumentException::class);

it('refuses a run ID that is not lowercase letters and digits', function (string $runId) {
    new ScannerCommands($this->server, $runId);
})->with(['short', 'UPPERCASE123', 'run-1234567', 'a;rm -rf /', str_repeat('a', 33)])->throws(InvalidArgumentException::class);

it('passes image refs as escaped shell arguments', function () {
    $digestRef = 'ghcr.io/a/b@sha256:'.str_repeat('ab', 32);
    $script = fn (array $commands) => implode("\n", scannerUnwrapped($commands));

    expect($script($this->commands->inspect('localhost:5000/team/app:1.2')))->toContain("'localhost:5000/team/app:1.2'")
        ->and($script($this->commands->sbomFromRegistry($digestRef, 'linux/amd64', 'c1', null)))->toContain("'--platform' 'linux/amd64' 'registry:{$digestRef}'")
        ->and($script($this->commands->digest($digestRef, null, null)))->toContain("'image' 'digest' '{$digestRef}'")
        ->and($script($this->commands->tags('nginx:1', null)))->toContain("'tag' 'ls' 'docker.io/library/nginx'");
});

it('refuses values that could break out of the command', function (string $method, array $arguments) {
    $this->commands->{$method}(...$arguments);
})->with([
    'image ref' => ['inspect', ['nginx;rm -rf /']],
    'local sbom ref' => ['sbomFromLocal', ['nginx$(id)', SCANNER_TEST_HEX]],
    'image id' => ['sbomFromLocal', ['nginx', 'sha256:'.SCANNER_TEST_HEX]],
    'sbom name' => ['grype', ['../etc/passwd']],
    'package sbom name' => ['packages', ['a b']],
    'registry sbom name' => ['sbomFromRegistry', ['nginx', 'linux/amd64', 'x;id', null]],
    'platform' => ['sbomFromRegistry', ['nginx', 'linux/amd64;id', 'c1', null]],
    'digest platform' => ['digest', ['nginx', 'linux', null]],
    'tags repository' => ['tags', ['nginx|id', null]],
    'auth not base64' => ['tags', ['nginx', "abc'; id; '"]],
    'auth with two registries' => ['tags', ['nginx', base64_encode(json_encode(['auths' => ['a.io' => ['auth' => 'x'], 'b.io' => ['auth' => 'y']]]))]],
    'auth with a credential helper' => ['tags', ['nginx', base64_encode(json_encode(['auths' => ['a.io' => ['auth' => 'x']], 'credsStore' => 'desktop']))]],
    'db url' => ['dbUpdate', ["https://x.io/db';id;'"]],
    'db url scheme' => ['dbUpdate', ['file:///etc/passwd']],
    'db url with a pipe' => ['dbUpdate', ['https://x.io/a|b']],
    'db url with an ampersand' => ['dbUpdate', ['https://x.io/a?b=1&&c=2']],
])->throws(InvalidArgumentException::class);

it('writes the SBOM to a temp file and moves it into place after Syft succeeds', function () {
    $commands = $this->commands->sbomFromLocal('nginx', SCANNER_TEST_HEX);
    $index = collect($commands)->search(fn (string $line) => str_contains($line, 'docker-archive:'));
    $syft = scannerUnwrapped($commands)[$index];
    $tmp = '/data/coolify/image-scans/sboms/'.SCANNER_TEST_HEX.'.json.tmp-'.SCANNER_TEST_RUN_ID;
    $final = '/data/coolify/image-scans/sboms/'.SCANNER_TEST_HEX.'.json';

    expect($commands[$index])->toStartWith("sh -c '")
        ->and($syft)->toContain("> '{$tmp}' 2>/dev/null && chown 65534:65534 '{$tmp}' && chmod 600 '{$tmp}' && mv -f '{$tmp}' '{$final}'; rc=\$?")
        ->and($commands)->toContain("rm -f '{$tmp}' || true");
});

it('saves the local image by its ID so a moved tag cannot change the cached SBOM', function () {
    $save = collect($this->commands->sbomFromLocal('nginx:1', SCANNER_TEST_HEX))->first(fn (string $line) => str_contains($line, 'docker save'));

    expect($save)->toContain("docker save -o '/data/coolify/image-scans/tmp-".SCANNER_TEST_RUN_ID."/in/image.tar' 'sha256:".SCANNER_TEST_HEX."' >/dev/null 2>&1")
        ->not->toContain('nginx');
});

it('reads tool output through a size cap and prints an exit marker', function () {
    foreach (['grype' => $this->commands->grype(SCANNER_TEST_HEX), 'packages' => $this->commands->packages(SCANNER_TEST_HEX), 'tags' => $this->commands->tags('nginx', null), 'digest' => $this->commands->digest('nginx', null, null), 'db-status' => $this->commands->dbStatus()] as $step => $commands) {
        $all = implode("\n", scannerUnwrapped($commands));

        expect($all)->toContain("head -c 10485761 '/data/coolify/image-scans/tmp-".SCANNER_TEST_RUN_ID."/out' 2>/dev/null || true")
            ->toContain("rc=\$?; echo \"coolify-scan-exit {$step} \$rc \$(wc -c < '/data/coolify/image-scans/tmp-".SCANNER_TEST_RUN_ID."/out' 2>/dev/null || echo 0)\"")
            ->and(array_search('echo coolify-scan-payload', $commands, true))->toBeLessThan(collect($commands)->search(fn (string $line) => str_starts_with($line, 'head -c')))
            ->and(end($commands))->toBe("rm -rf '/data/coolify/image-scans/tmp-".SCANNER_TEST_RUN_ID."' || true");
    }
});

it('mounts the database and the SBOM read-only for Grype', function () {
    $run = scannerDockerRuns($this->commands->grype(SCANNER_TEST_HEX))[0];

    expect($run)->toContain("--mount 'type=volume,src=coolify-grype-db,dst=/db,readonly'")
        ->toContain("--mount 'type=bind,src=/data/coolify/image-scans/sboms/".SCANNER_TEST_HEX.".json,dst=/sbom/sbom.json,readonly'")
        ->toContain("-e 'GRYPE_DB_AUTO_UPDATE=false'")
        ->toContain('sbom:/sbom/sbom.json');
});

it('uploads the output templates from the resources', function () {
    $templateLine = collect($this->commands->grype(SCANNER_TEST_HEX))->first(fn (string $line) => str_contains($line, 'base64 -d'));

    preg_match("/^echo '([A-Za-z0-9+\\/=]+)' \\| base64 -d \\| tee '([^']+)' >\\/dev\\/null$/", $templateLine, $matches);

    expect(base64_decode($matches[1], true))->toBe(file_get_contents(resource_path('image-scan/grype.tmpl')))
        ->and($matches[2])->toBe('/data/coolify/image-scans/tmp-'.SCANNER_TEST_RUN_ID.'/tpl/grype.tmpl');
});

it('writes registry credentials for one registry into a private file mounted read-only', function () {
    $auth = base64_encode(json_encode(['auths' => ['ghcr.io' => ['auth' => base64_encode('user:token')]]]));
    $commands = $this->commands->tags('ghcr.io/a/b', $auth);
    $config = '/data/coolify/image-scans/tmp-'.SCANNER_TEST_RUN_ID.'/auth/config.json';

    expect($commands)->toContain("chmod 600 '{$config}'")
        ->and(implode("\n", $commands))->toContain("| base64 -d | tee '{$config}' >/dev/null")
        ->and(scannerDockerRuns($commands)[0])->toContain("-e 'DOCKER_CONFIG=/auth'")
        ->toContain('dst=/auth,readonly');

    expect(implode("\n", $this->commands->tags('ghcr.io/a/b', null)))->not->toContain('DOCKER_CONFIG')->not->toContain('/auth');
});

it('keeps registry credentials out of sudo arguments for a non-root SSH user', function () {
    $secret = base64_encode('user:secret-token');
    $auth = base64_encode(json_encode(['auths' => ['ghcr.io' => ['auth' => $secret]]]));
    $written = base64_encode(json_encode(['auths' => ['ghcr.io' => ['auth' => $secret]]], JSON_UNESCAPED_SLASHES));
    $lists = [
        $this->commands->tags('ghcr.io/a/b', $auth),
        $this->commands->digest('ghcr.io/a/b:1', null, $auth),
        $this->commands->sbomFromRegistry('ghcr.io/a/b:1', 'linux/amd64', 'c1', $auth),
    ];

    foreach ($lists as $commands) {
        $parsed = parseCommandsByLineForSudo(collect($commands), $this->server);

        expect(implode("\n", $parsed))->not->toContain('sudo printf')->toContain("echo '{$written}' | sudo base64 -d");
        foreach ($parsed as $line) {
            $sudo = strpos($line, 'sudo');
            $secretAt = strpos($line, $written);
            expect($secretAt === false || $sudo === false || $sudo > $secretAt)->toBeTrue($line);
        }
    }
});

it('accepts credentials only for the registry of the image', function (string $key, string $ref) {
    $auth = base64_encode(json_encode(['auths' => [$key => ['auth' => 'eA==']]]));

    expect($this->commands->digest($ref, null, $auth))->toBeArray();
})->with([
    ['ghcr.io', 'ghcr.io/a/b:1'],
    ['https://ghcr.io', 'ghcr.io/a/b:1'],
    ['localhost:5000', 'localhost:5000/team/app:1.2'],
    ['https://index.docker.io/v1/', 'nginx'],
    ['index.docker.io', 'grafana/grafana'],
    ['docker.io', 'nginx'],
    ['registry-1.docker.io', 'nginx'],
]);

it('refuses credentials for another registry', function (string $key, string $ref) {
    $auth = base64_encode(json_encode(['auths' => [$key => ['auth' => 'eA==']]]));

    $this->commands->tags($ref, $auth);
})->with([
    ['ghcr.io', 'nginx'],
    ['https://index.docker.io/v1/', 'ghcr.io/a/b'],
    ['ghcr.io.evil.com', 'ghcr.io/a/b'],
    ['localhost:5001', 'localhost:5000/app'],
])->throws(InvalidArgumentException::class);

it('removes the work directory, the leftover temp files, and the container in cleanup', function () {
    expect($this->commands->cleanup())->toContain("rm -rf '/data/coolify/image-scans/tmp-".SCANNER_TEST_RUN_ID."' || true")
        ->toContain("find '/data/coolify/image-scans/sboms' -maxdepth 1 -name '*.json.tmp-*' -mmin +60 -delete 2>/dev/null || true")
        ->and($this->commands->deleteScannerData())->toContain("rm -rf '/data/coolify/image-scans' || true")
        ->toContain('docker volume rm -f coolify-grype-db >/dev/null 2>&1 || true');
});

it('keeps every command list working for a non-root SSH user', function () {
    foreach (scannerCommandLists($this->commands) as $name => $commands) {
        $parsed = parseCommandsByLineForSudo(collect($commands), $this->server);

        foreach ($parsed as $index => $line) {
            $original = $commands[$index];

            expect($line)->not->toContain('sudo sudo')
                ->not->toContain('chown -h ubuntu');

            if (str_starts_with($original, 'sh -c ')) {
                expect($line)->toBe('sudo '.$original, $name)
                    ->and(isSingleSudoShellScript($line))->toBeTrue($name)
                    ->and(substr_count($line, 'sudo'))->toBe(1, $name);

                continue;
            }
            if (str_starts_with($original, 'if ')) {
                expect($line)->toMatch('/^if sudo (timeout -k 10 \d+ docker|docker image inspect) /', $name)
                    ->toContain("; then echo 'coolify-scan-exit ")
                    ->toEndWith('$?"; fi', $name);

                continue;
            }
            if (str_starts_with($original, 'echo ')) {
                expect($line)->toBe(str_replace(' | ', ' | sudo ', $original), $name)
                    ->toMatch("/^echo (coolify-scan-payload|'[A-Za-z0-9+\\/=]+' \\| sudo base64 -d \\| sudo tee '[^']+' >\\/dev\\/null)$/", $name);

                continue;
            }

            expect($line)->toMatch('/^sudo (docker|mkdir|chown|chmod|rm|head|df|find) /', $name);
            if (str_ends_with($original, ' || true')) {
                expect($line)->toEndWith(' || sudo true', $name);
            }
        }
    }
});

it('keeps the docker root lookup inside the disk check for a non-root SSH user', function () {
    $parsed = parseCommandsByLineForSudo(collect($this->commands->diskFree()), $this->server);

    expect($parsed[2])->toBe("sudo df -P -B1 \"\$(sudo docker info -f '{{.DockerRootDir}}')\" | sudo awk 'NR==2 {printf \"%s \", \$4}'")
        ->and($parsed[3])->toBe("sudo df -P -B1 '/data/coolify/image-scans' | sudo awk 'NR==2 {print \$4}'");
});

it('prints the image ID, the repo digest, the platform, and the size in one line', function () {
    $commands = $this->commands->inspect('nginx:1');

    expect($commands[2])->toBe('echo coolify-scan-payload')
        ->and($commands[3])->toBe("docker image inspect --format '{{.Id}}|{{if .RepoDigests}}{{index .RepoDigests 0}}{{end}}|{{.Os}}/{{.Architecture}}|{{.Size}}' 'nginx:1' 2>/dev/null || true");
});

it('parses exit markers before the payload separator', function () {
    $output = "coolify-scan-exit save 0\ncoolify-scan-exit sbom 0\ncoolify-scan-exit grype 0 12\ncoolify-scan-payload\n{\"a\":1}\ncoolify-scan-payload\n{\"b\":2}";

    expect(ScannerCommands::parseOutput($output))->toBe([
        'exit_codes' => ['save' => 0, 'sbom' => 0, 'grype' => 0],
        'sizes' => ['grype' => 12],
        'payload' => "{\"a\":1}\ncoolify-scan-payload\n{\"b\":2}",
    ])->and(ScannerCommands::parseOutput("coolify-scan-exit save 0\ncoolify-scan-exit sbom 1"))->toBe([
        'exit_codes' => ['save' => 0, 'sbom' => 1], 'sizes' => [], 'payload' => '',
    ])->and(ScannerCommands::parseOutput(null))->toBe(['exit_codes' => [], 'sizes' => [], 'payload' => '']);
});

it('ignores payload lines that look like exit markers', function () {
    $output = "coolify-scan-exit grype 2 9\ncoolify-scan-payload\ncoolify-scan-exit grype 0 9\n{}";

    expect(ScannerCommands::parseOutput($output))->toBe([
        'exit_codes' => ['grype' => 2],
        'sizes' => ['grype' => 9],
        'payload' => "coolify-scan-exit grype 0 9\n{}",
    ]);
});
