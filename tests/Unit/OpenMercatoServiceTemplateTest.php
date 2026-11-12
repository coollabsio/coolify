<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function openMercatoTemplate(): array
{
    return Yaml::parseFile(__DIR__.'/../../templates/compose/open-mercato.yaml');
}

it('keeps databases private and persists application state', function () {
    $compose = openMercatoTemplate();

    foreach ($compose['services'] as $service) {
        expect($service)->not->toHaveKey('ports');
    }

    expect($compose['services']['app']['volumes'])->toContain(
        'init-state:/var/lib/open-mercato',
        'attachments:/app/apps/mercato/storage',
        'queue:/app/apps/mercato/.mercato/queue',
    );

    expect($compose['services']['app']['environment']['APP_URL'])->toBe('${SERVICE_URL_APP}');
    expect(__DIR__.'/../../public/svgs/open-mercato.svg')->toBeFile();
});

it('pins the public source and container dependencies', function () {
    $compose = openMercatoTemplate();
    $build = $compose['services']['app']['build'];

    expect($build['context'])->toMatch('/^https:\/\/github\.com\/open-mercato\/open-mercato\.git#[a-f0-9]{40}$/');
    expect($build['dockerfile_inline'])->toContain('USER omuser');
    expect($build['dockerfile_inline'])->toMatch('/FROM node:[^\s]+@sha256:[a-f0-9]{64}/');

    foreach (['postgres', 'redis', 'meilisearch'] as $name) {
        expect($compose['services'][$name]['image'])->toMatch('/@sha256:[a-f0-9]{64}$/');
    }
});

it('stops on failed bootstrap or migration and seeds only once', function () {
    $directory = sys_get_temp_dir().'/mercato-startup-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    mkdir($directory.'/state', 0700);
    file_put_contents($directory.'/yarn', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$CALL_LOG"
if [ "$*" = 'mercato init --no-examples --skip-password-policy=false' ]; then
    echo 'private-bootstrap-output'
    exit "${INIT_EXIT:-0}"
fi
if [ "$*" = 'db:migrate' ]; then
    exit "${MIGRATE_EXIT:-0}"
fi
SH);
    chmod($directory.'/yarn', 0700);
    $command = str_replace('/var/lib/open-mercato', $directory.'/state', openMercatoTemplate()['services']['app']['command']);
    $environment = ['PATH' => $directory.':'.getenv('PATH'), 'CALL_LOG' => $directory.'/calls'];
    $run = function (array $overrides = []) use ($command, $environment) {
        $process = new Process($command, env: array_merge($environment, $overrides));
        $process->run();

        return $process;
    };

    try {
        $failedInit = $run(['INIT_EXIT' => '9']);
        expect($failedInit->isSuccessful())->toBeFalse();
        expect($failedInit->getOutput())->not->toContain('private-bootstrap-output');
        expect(file_exists($directory.'/state/.initialized'))->toBeFalse();
        expect(file_get_contents($directory.'/calls'))->not->toContain('start');
        expect(fileperms($directory.'/state/bootstrap.log') & 0777)->toBe(0600);

        file_put_contents($directory.'/calls', '');
        expect($run()->isSuccessful())->toBeTrue();
        expect($directory.'/state/.initialized')->toBeFile();
        expect(file_get_contents($directory.'/calls'))->toContain('mercato init', 'start');

        file_put_contents($directory.'/calls', '');
        expect($run(['MIGRATE_EXIT' => '8'])->isSuccessful())->toBeFalse();
        expect(file_get_contents($directory.'/calls'))->toBe("db:migrate\n");

        file_put_contents($directory.'/calls', '');
        expect($run()->isSuccessful())->toBeTrue();
        expect(file_get_contents($directory.'/calls'))->toBe("db:migrate\nmercato auth sync-role-acls\nstart\n");
    } finally {
        foreach (glob($directory.'/state/*') as $file) {
            unlink($file);
        }
        if (file_exists($directory.'/state/.initialized')) {
            unlink($directory.'/state/.initialized');
        }
        rmdir($directory.'/state');
        unlink($directory.'/yarn');
        unlink($directory.'/calls');
        rmdir($directory);
    }
});
