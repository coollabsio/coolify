<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

function makeRawComposeApplication(string $compose): Application
{
    $application = new Application;
    $application->uuid = 'compose-volume-test';
    $application->docker_compose_raw = $compose;

    $server = new Server;
    $server->ip = '127.0.0.1';
    $server->user = 'root';

    $destination = new StandaloneDocker;
    $destination->setRelation('server', $server);
    $application->setRelation('destination', $destination);

    return $application;
}

function rawComposeBindSource(string $source, string $syntax = 'long'): string
{
    if ($syntax === 'short') {
        $volume = trim($source, '"');

        return <<<YAML
services:
  web:
    image: nginx:alpine
    volumes:
      - "{$volume}:/usr/share/nginx/html"
YAML;
    }

    return <<<YAML
services:
  web:
    image: nginx:alpine
    volumes:
      - type: bind
        source: {$source}
        target: /usr/share/nginx/html
YAML;
}

it('rejects invalid long-form bind sources before remote work', function () {
    Process::fake();

    $application = makeRawComposeApplication(rawComposeBindSource('"/srv/data;extra"'));

    expect(fn () => $application->oldRawParser())
        ->toThrow(Exception::class, 'forbidden character');

    Process::assertNothingRan();
});

it('rejects invalid short-form bind sources before remote work', function () {
    Process::fake();

    $application = makeRawComposeApplication(rawComposeBindSource('"/srv/data;extra"', 'short'));

    expect(fn () => $application->oldRawParser())
        ->toThrow(Exception::class, 'forbidden character');

    Process::assertNothingRan();
});

it('rejects unsupported expansion syntax in long-form bind sources', function () {
    Process::fake();

    $application = makeRawComposeApplication(rawComposeBindSource('"/srv/data$(value)"'));

    expect(fn () => $application->oldRawParser())
        ->toThrow(Exception::class, 'command substitution');

    Process::assertNothingRan();
});

it('rejects unsupported quoted syntax in long-form bind sources', function () {
    Process::fake();

    $application = makeRawComposeApplication(rawComposeBindSource('"/srv/data`value`"'));

    expect(fn () => $application->oldRawParser())
        ->toThrow(Exception::class, 'backtick');

    Process::assertNothingRan();
});

it('rejects unsupported operators in bind sources', function (string $source) {
    Process::fake();

    $application = makeRawComposeApplication(rawComposeBindSource($source));

    expect(fn () => $application->oldRawParser())
        ->toThrow(Exception::class, 'forbidden character');

    Process::assertNothingRan();
})->with([
    '"/srv/data | extra"',
    '"/srv/data && extra"',
    '"/srv/data || extra"',
    '"/srv/data > extra"',
    '"/srv/data < extra"',
]);

it('rejects newlines and control characters in volume sources', function () {
    Process::fake();

    $application = makeRawComposeApplication(<<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - type: bind
        source: "/srv/data
extra"
        target: /usr/share/nginx/html
YAML);

    expect(fn () => $application->oldRawParser())
        ->toThrow(Exception::class);

    Process::assertNothingRan();
});

it('rejects invalid Compose variable defaults in bind sources', function () {
    Process::fake();

    $application = makeRawComposeApplication(rawComposeBindSource('"${DATA:-$(value)}"'));

    expect(fn () => $application->oldRawParser())
        ->toThrow(Exception::class);

    Process::assertNothingRan();
});

it('quotes safe absolute bind sources at the mkdir execution boundary', function () {
    expect(rawComposeBindMkdirCommand('/srv/appdata'))
        ->toBe("mkdir -p -- '/srv/appdata' > /dev/null 2>&1 || true");

    expect(fn () => rawComposeBindMkdirCommand('/srv/appdata;extra'))
        ->toThrow(Exception::class);
});

it('quotes paths that begin with a command option', function () {
    expect(rawComposeBindMkdirCommand('-evil'))
        ->toBe("mkdir -p -- '-evil' > /dev/null 2>&1 || true");
});

it('rejects blank and null-byte bind sources before a remote command runs', function (string $source) {
    Process::fake();

    $application = makeRawComposeApplication(rawComposeBindSource(json_encode($source)));

    expect(fn () => $application->oldRawParser())->toThrow(Exception::class, 'Invalid volume source');
    Process::assertNothingRan();
})->with([' ', "\0"]);

it('quotes quotes and backslashes so they cannot change the mkdir command', function () {
    expect(rawComposeBindMkdirCommand("/tmp/foo'bar"))
        ->toBe("mkdir -p -- '/tmp/foo'\\''bar' > /dev/null 2>&1 || true")
        ->and(rawComposeBindMkdirCommand('/tmp/foo\\bar'))
        ->toBe("mkdir -p -- '/tmp/foo\\bar' > /dev/null 2>&1 || true")
        ->and(rawComposeBindMkdirCommand('$HOME'))
        ->toBe("mkdir -p -- '\$HOME' > /dev/null 2>&1 || true");
});

it('does not expand compose environment interpolations through the server shell', function () {
    expect(rawComposeBindMkdirCommand('${DATA_PATH}'))->toBeNull()
        ->and(rawComposeBindMkdirCommand('${DATA_PATH}/mysql'))->toBeNull()
        ->and(rawComposeBindMkdirCommand('${DATA_PATH:-/srv/appdata}'))->toBeNull();
});

it('preserves legitimate long-form bind mounts', function () {
    Process::fake();

    $application = makeRawComposeApplication(rawComposeBindSource('"/srv/appdata"'));

    $application->oldRawParser();

    expect($application->docker_compose_raw)->toContain('/srv/appdata');
});

it('preserves legitimate short-form relative bind mounts', function () {
    Process::fake();

    $application = makeRawComposeApplication(rawComposeBindSource('./data', 'short'));

    $application->oldRawParser();

    expect($application->docker_compose_raw)->toContain('./data');
});

it('preserves nested relative bind mounts', function () {
    Process::fake();

    $application = makeRawComposeApplication(rawComposeBindSource('./data/nested/dir', 'short'));

    $application->oldRawParser();

    expect($application->docker_compose_raw)->toContain('./data/nested/dir');
});

it('does not treat named volumes as bind sources', function () {
    Process::fake();

    $application = makeRawComposeApplication(<<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - appdata:/var/lib/data
volumes:
  appdata:
YAML);

    $application->oldRawParser();

    expect($application->docker_compose_raw)->toContain('appdata:/var/lib/data');
});

it('does not treat long-form named volumes as bind sources', function () {
    Process::fake();

    $application = makeRawComposeApplication(<<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - type: volume
        source: appdata
        target: /var/lib/data
volumes:
  appdata:
YAML);

    $application->oldRawParser();

    expect($application->docker_compose_raw)->toContain('appdata');
});

it('still uses oldRawParser for raw compose deployments after git compose load', function () {
    $jobSource = file_get_contents(base_path('app/Jobs/ApplicationDeploymentJob.php'));
    $parserSource = file_get_contents(base_path('app/Models/Application.php'));

    expect($jobSource)
        ->toContain('loadComposeFile(isInit: false)')
        ->toContain('is_raw_compose_deployment_enabled')
        ->toContain('oldRawParser()')
        ->and($parserSource)
        ->toContain('rawComposeBindMkdirCommand(')
        ->toContain('escapeshellarg');
});
