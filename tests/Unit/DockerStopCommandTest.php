<?php

/**
 * Docker 28.0 deprecated `--time` on `docker stop` / `docker restart` in favor of `--timeout`.
 * `--timeout` is rejected by Docker < 28, so Coolify picks the flag from the stored engine version.
 * Unknown versions keep the historical `--time` flag.
 *
 * @see https://github.com/coollabsio/coolify/issues/11244
 */
it('parses docker engine versions', function (?string $raw, ?string $expected) {
    expect(parseDockerEngineVersion($raw))->toBe($expected);
})->with([
    'semver' => ['29.4.3', '29.4.3'],
    'two-part' => ['28.0', '28.0.0'],
    'suffix' => ['28.0.1-ce', '28.0.1'],
    'build metadata' => ['29.4.3+azure', '29.4.3'],
    'null' => [null, null],
    'empty' => ['', null],
    'garbage' => ['not-a-version', null],
]);

it('extracts the server version from docker version json', function () {
    expect(dockerEngineVersionFromJson('{"Server":{"Version":"29.4.3"}}'))->toBe('29.4.3')
        ->and(dockerEngineVersionFromJson('{"Client":{"Version":"29.4.3"}}'))->toBeNull()
        ->and(dockerEngineVersionFromJson('not-json'))->toBeNull();
});

it('uses --timeout on docker 28 and newer', function (string $version) {
    expect(dockerStopCommand(30, 'app-1', $version))->toBe('docker stop --timeout=30 app-1');
})->with(['28.0.0', '28.0.1', '28.0', '29.4.3', '29.4.3-ce']);

it('uses --time on docker older than 28', function (string $version) {
    expect(dockerStopCommand(30, 'app-1', $version))->toBe('docker stop --time=30 app-1');
})->with(['24.0.0', '26.1.4', '27.5.1']);

it('uses the historical --time flag when the docker version is unknown', function (?string $version) {
    expect(dockerStopCommand(30, 'app-1', $version))->toBe('docker stop --time=30 app-1');
})->with([
    'null' => [null],
    'empty' => [''],
    'garbage' => ['unknown'],
]);
