<?php

use Symfony\Component\Yaml\Yaml;

it('includes a production-ready Notifuse one-click service template', function () {
    $templatePath = __DIR__.'/../../templates/compose/notifuse.yaml';

    expect($templatePath)->toBeFile();

    $source = file_get_contents($templatePath);
    $compose = Yaml::parse($source);
    $services = $compose['services'];
    $notifuse = $services['notifuse'];

    expect($source)
        ->toContain('# documentation: https://docs.notifuse.com/self-hosting/installation')
        ->toContain('# category: email')
        ->toContain('# logo: svgs/notifuse.svg')
        ->toContain('# port: 8080')
        ->and(array_keys($services))->toBe(['notifuse', 'postgres'])
        ->and($notifuse['image'])->toBe('notifuse/notifuse:latest')
        ->and($services['postgres']['image'])->toBe('postgres:17-alpine')
        ->and($notifuse['environment'])->toContain('SERVICE_URL_NOTIFUSE_8080')
        ->and($notifuse['environment'])->toContain('API_ENDPOINT=${SERVICE_URL_NOTIFUSE}')
        ->and($notifuse['environment'])->toContain('SECRET_KEY=${SERVICE_REALBASE64_64_NOTIFUSE}')
        ->and($notifuse['environment'])->toContain('DB_USER=$SERVICE_USER_POSTGRES')
        ->and($notifuse['environment'])->toContain('DB_PASSWORD=$SERVICE_PASSWORD_POSTGRES')
        ->and($notifuse['environment'])->toContain('DB_HOST=postgres')
        ->and($services['postgres']['environment'])->toContain('POSTGRES_USER=$SERVICE_USER_POSTGRES')
        ->and($services['postgres']['environment'])->toContain('POSTGRES_PASSWORD=$SERVICE_PASSWORD_POSTGRES')
        ->and($notifuse['volumes'])->toContain('notifuse-data:/app/data')
        ->and($notifuse['healthcheck']['test'])->toBe(['CMD', 'wget', '--no-verbose', '--tries=1', '--spider', 'http://localhost:8080/healthz'])
        ->and($notifuse['depends_on']['postgres']['condition'])->toBe('service_healthy')
        ->and($services['postgres']['healthcheck']['test'][0])->toBe('CMD-SHELL');
});
