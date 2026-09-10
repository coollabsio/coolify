<?php

use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

it('parses valid Docker Compose files with more than 128 collection aliases', function () {
    Process::fake();

    $services = collect(range(1, 129))
        ->mapWithKeys(fn (int $index): array => ["service-{$index}" => [
            '<<' => '*defaults',
        ]])
        ->map(fn (array $service, string $name): string => "  {$name}:\n    <<: {$service['<<']}\n")
        ->implode('');

    $application = new Application;
    $application->docker_compose_raw = "x-defaults: &defaults\n  image: alpine:latest\nservices:\n{$services}";

    $server = new Server;
    $server->ip = '127.0.0.1';
    $server->user = 'root';

    $destination = new StandaloneDocker;
    $destination->setRelation('server', $server);
    $application->setRelation('destination', $destination);

    $application->oldRawParser();

    $parsedCompose = Yaml::parse($application->docker_compose_raw);

    expect(data_get($parsedCompose, 'services'))->toHaveCount(129)
        ->and(data_get($parsedCompose, 'services.service-129.image'))->toBe('alpine:latest')
        ->and(data_get($parsedCompose, 'services.service-129.labels'))->toContain('coolify.managed=true');
});

it('keeps a finite collection alias limit for Docker Compose files', function () {
    $services = collect(range(1, Application::MAX_DOCKER_COMPOSE_COLLECTION_ALIASES + 1))
        ->map(fn (int $index): string => "  service-{$index}:\n    <<: *defaults\n")
        ->implode('');

    $application = new Application;
    $application->docker_compose_raw = "x-defaults: &defaults\n  image: alpine:latest\nservices:\n{$services}";

    $application->oldRawParser();
})->throws(RuntimeException::class, 'Maximum number of collection aliases (256) exceeded');
