<?php

use App\Jobs\CleanupHelperContainersJob;
use App\Models\Server;
use Symfony\Component\Process\Process;

it('matches helper image references without matching similarly named images', function () {
    $command = (new ReflectionMethod(CleanupHelperContainersJob::class, 'helperContainersCommand'))->invoke(null);
    $directory = sys_get_temp_dir().'/coolify-helper-filter-'.bin2hex(random_bytes(4));
    $docker = $directory.'/docker';
    $images = [
        'coollabsio/coolify-helper:1.0.15',
        'docker.io/coollabsio/coolify-helper:1.0.16',
        'ghcr.io/coollabsio/coolify-helper@sha256:abc',
        'registry.example/team/coollabsio/coolify-helper:latest',
        'coollabsio/coolify:latest',
        'coollabsio/coolify:4.3.12',
        'coollabsio/coolify-realtime:1.0.10',
        'coolify-helper:latest',
        'someone/coolify-helper:1.0.16',
        'evil/coollabsio/coolify-helper-copy:latest',
        'coollabsio/not-coolify-helper:latest',
    ];

    mkdir($directory);
    file_put_contents($docker, "#!/bin/sh\n".implode("\n", array_map(
        fn (string $image): string => 'echo '.escapeshellarg(json_encode(['Image' => $image], JSON_THROW_ON_ERROR)),
        $images
    ))."\n");
    chmod($docker, 0755);

    try {
        $process = new Process(['/bin/sh', '-c', $command], env: [
            'PATH' => $directory.':'.getenv('PATH'),
        ]);
        $process->mustRun();

        expect(array_column(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'Image'))
            ->toBe(array_slice($images, 0, 4));
    } finally {
        unlink($docker);
        rmdir($directory);
    }
});

it('preserves the helper image filter for non-root servers', function () {
    $command = (new ReflectionMethod(CleanupHelperContainersJob::class, 'helperContainersCommand'))->invoke(null);
    $server = Mockery::mock(Server::class)->makePartial();
    $server->user = 'ubuntu';
    $command = parseCommandsByLineForSudo(collect([$command]), $server)[0];
    $directory = sys_get_temp_dir().'/coolify-helper-sudo-filter-'.bin2hex(random_bytes(4));
    $docker = $directory.'/docker';
    $sudo = $directory.'/sudo';

    mkdir($directory);
    file_put_contents($docker, "#!/bin/sh\necho '{\"Image\":\"coollabsio/coolify-helper:1.0.15\"}'\n");
    file_put_contents($sudo, "#!/bin/sh\nexec \"\$@\"\n");
    chmod($docker, 0755);
    chmod($sudo, 0755);

    try {
        $process = new Process(['/bin/sh', '-c', $command], env: [
            'PATH' => $directory.':'.getenv('PATH'),
        ]);
        $process->mustRun();

        expect(array_column(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'Image'))
            ->toBe(['coollabsio/coolify-helper:1.0.15']);
    } finally {
        unlink($docker);
        unlink($sudo);
        rmdir($directory);
    }
});
