<?php

use App\Data\RepositoryDetectionResult;
use App\Models\Application;
use App\Traits\HasRepositoryDetection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Minimal host that mimics the Livewire components using the trait.
 */
class RepositoryDetectionHost
{
    use HasRepositoryDetection;

    public string $build_pack = 'railpack';

    public int $port = 3000;

    public ?string $docker_compose_location = '/docker-compose.yaml';

    protected function applicationForDetection(): Application
    {
        return $this->unsavedApplicationForDetection('test/repo', 'main');
    }

    public function updatedBuildPack(): void {}

    public function resetErrorBag($field = null): void {}

    public function dockerfileLocation(): ?string
    {
        return $this->selectedDockerfileLocation();
    }

    public function apply(RepositoryDetectionResult $result): void
    {
        $this->applyDetectionResult($result);
    }
}

test('env vars are flattened to plain key value strings for the import form', function () {
    $host = new RepositoryDetectionHost;

    $host->apply(new RepositoryDetectionResult(
        envFiles: [
            '.env.example' => "APP_NAME=MyApp\nAPP_DEBUG=true\nAPP_URL=\"https://example.com\"",
        ],
    ));

    expect($host->envExampleVars)->toBe([
        'APP_NAME' => 'MyApp',
        'APP_DEBUG' => 'true',
        'APP_URL' => 'https://example.com',
    ]);

    // No value should be an array (which would render as "[object Object]" in the UI).
    foreach ($host->envExampleVars as $value) {
        expect($value)->toBeString();
    }
});

test('switching the selected env file keeps values flat', function () {
    $host = new RepositoryDetectionHost;

    $host->apply(new RepositoryDetectionResult(
        envFiles: [
            '.env.example' => 'FOO=bar',
            '.env.sample' => 'BAZ=qux',
        ],
    ));

    $host->selectedEnvFile = '.env.sample';
    $host->updatedSelectedEnvFile();

    expect($host->envExampleVars)->toBe(['BAZ' => 'qux']);
});

test('empty scan preserves the chosen build pack and clears previous results', function () {
    $host = new RepositoryDetectionHost;
    $host->apply(new RepositoryDetectionResult(envFiles: ['.env.example' => 'FOO=bar']));
    $host->envImported = true;
    $host->apply(RepositoryDetectionResult::none());

    expect($host->build_pack)->toBe('railpack')
        ->and($host->envExampleVars)->toBe([])
        ->and($host->detectedEnvFiles)->toBe([])
        ->and($host->selectedEnvFile)->toBeNull()
        ->and($host->envImported)->toBeFalse();
});

test('switching to a Dockerfile without EXPOSE resets the detected port', function () {
    $host = new RepositoryDetectionHost;
    $host->apply(new RepositoryDetectionResult(
        dockerfiles: ['Dockerfile', 'Dockerfile.worker'],
        dockerfilePorts: ['Dockerfile' => 8080, 'Dockerfile.worker' => null],
    ));
    $host->selectedDockerfile = 'Dockerfile.worker';
    $host->updatedSelectedDockerfile();

    expect($host->port)->toBe(3000)->and($host->detectedPort)->toBeNull();
});

test('a typed Dockerfile path that was not detected is kept', function () {
    $host = new RepositoryDetectionHost;
    $host->apply(new RepositoryDetectionResult(dockerfiles: ['Dockerfile'], dockerfilePorts: ['Dockerfile' => 8080]));
    $host->selectedDockerfile = ' /docker/prod.Dockerfile ';
    $host->updatedSelectedDockerfile();

    expect($host->selectedDockerfile)->toBe('docker/prod.Dockerfile')
        ->and($host->dockerfileLocation())->toBe('/docker/prod.Dockerfile')
        ->and($host->port)->toBe(3000);
});

test('a typed Dockerfile path with unsafe characters is rejected', function () {
    $host = new RepositoryDetectionHost;
    $host->selectedDockerfile = 'Dockerfile; rm -rf /';

    expect(fn () => $host->updatedSelectedDockerfile())->toThrow(ValidationException::class);
});

test('an empty Dockerfile selection keeps the default location', function () {
    $host = new RepositoryDetectionHost;
    $host->selectedDockerfile = '  ';
    $host->updatedSelectedDockerfile();

    expect($host->selectedDockerfile)->toBeNull()->and($host->dockerfileLocation())->toBeNull();
});

test('a typed Docker Compose path sets the compose location', function () {
    $host = new RepositoryDetectionHost;
    $host->apply(new RepositoryDetectionResult(dockerComposeFiles: ['docker-compose.yml']));
    $host->selectedDockerComposeFile = '/deploy/compose.prod.yaml';
    $host->updatedSelectedDockerComposeFile();

    expect($host->selectedDockerComposeFile)->toBe('deploy/compose.prod.yaml')
        ->and($host->docker_compose_location)->toBe('/deploy/compose.prod.yaml');
});

test('nested build files do not change the build pack', function () {
    $host = new RepositoryDetectionHost;
    $host->apply(new RepositoryDetectionResult(
        dockerfiles: ['apps/api/Dockerfile'],
        dockerComposeFiles: ['examples/demo/docker-compose.yml'],
    ));

    expect($host->build_pack)->toBe('railpack')
        ->and($host->suggestedBuildPack)->toBeNull()
        ->and($host->detectedDockerComposeFiles)->toBe(['examples/demo/docker-compose.yml']);
});
