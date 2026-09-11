<?php

use App\Actions\Server\InstallSentinelHost;
use App\Models\Server;
use Illuminate\Support\Facades\Process;

it('builds an idempotent host installation script', function () {
    $script = InstallSentinelHost::installationScript(
        token: 'sentinel-token',
        endpoint: 'http://coolify:8000/api/v1/sentinel',
        image: 'ghcr.io/coollabsio/sentinel-host:main',
    );
    $environment = InstallSentinelHost::environmentFile('sentinel-token', 'http://coolify:8000/api/v1/sentinel');
    $unit = InstallSentinelHost::serviceUnit();

    expect($script)
        ->toContain("docker pull 'ghcr.io/coollabsio/sentinel-host:main'")
        ->toContain('docker create "$image" /sentinel')
        ->toContain('docker cp "$container_id:/sentinel" "$temporary_binary"')
        ->toContain('install -m 0755 "$temporary_binary" /usr/local/bin/sentinel.new')
        ->toContain('mv -f /usr/local/bin/sentinel.new /usr/local/bin/sentinel')
        ->and($environment)->toContain('CONTROL_PLANE_ENABLED=true')
        ->toContain('COLLECTOR_ENABLED=false')
        ->toContain('STORAGE_ENABLED=false')
        ->toContain('TRAFFIC_ENABLED=false')
        ->toContain('TOKEN=sentinel-token')
        ->toContain('PUSH_ENDPOINT=http://coolify:8000/api/v1/sentinel')
        ->and($unit)->toContain('ExecStart=/usr/local/bin/sentinel')
        ->toContain('EnvironmentFile=/etc/coolify/sentinel.env')
        ->and($script)
        ->toContain('systemctl enable sentinel.service')
        ->toContain('systemctl restart sentinel.service')
        ->toContain('systemctl is-active --quiet sentinel.service')
        ->toContain('curl --fail --silent http://127.0.0.1:8888/api/health');
});

it('can use an existing local host image without pulling it', function () {
    $script = InstallSentinelHost::installationScript(
        token: 'sentinel-token',
        endpoint: 'http://coolify:8000/api/v1/sentinel',
        image: 'coolify-sentinel-host:dev',
        skipImagePull: true,
    );

    expect($script)
        ->not->toContain('docker pull')
        ->toContain("image='coolify-sentinel-host:dev'")
        ->toContain('docker create "$image" /sentinel');
});

it('rejects unsafe installer inputs', function (string $token, string $endpoint, string $image) {
    expect(fn () => InstallSentinelHost::installationScript($token, $endpoint, $image))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'token with a newline' => ["token\nvalue", 'https://coolify.example/api/v1/sentinel', 'ghcr.io/coollabsio/sentinel-host:main'],
    'invalid endpoint' => ['token', 'not-a-url', 'ghcr.io/coollabsio/sentinel-host:main'],
    'unsafe image' => ['token', 'https://coolify.example/api/v1/sentinel', 'ghcr.io/coollabsio/sentinel-host:main; reboot'],
]);

it('does not use ssh while the host agent feature is disabled', function () {
    config()->set('constants.sentinel.host_enabled', false);
    Process::fake();

    expect(InstallSentinelHost::run(new Server))->toBeNull();

    Process::assertNothingRan();
});

it('does not use ssh outside development', function () {
    config()->set('app.env', 'production');
    config()->set('constants.sentinel.host_enabled', true);
    Process::fake();

    expect(InstallSentinelHost::run(new Server))->toBeNull();

    Process::assertNothingRan();
});

it('elevates the encoded installer for a non-root server user', function () {
    $server = new Server(['user' => 'coolify']);
    $command = 'printf %s encoded-script | base64 -d | bash';

    expect(parseCommandsByLineForSudo(collect([$command]), $server)[0])
        ->toStartWith("sudo bash -c '")
        ->toContain('base64 -d | bash');
});
