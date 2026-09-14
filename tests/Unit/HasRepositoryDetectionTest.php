<?php

use App\Data\RepositoryDetectionResult;
use App\Traits\HasRepositoryDetection;

/**
 * Minimal host that mimics the Livewire components using the trait.
 */
class RepositoryDetectionHost
{
    use HasRepositoryDetection;

    public string $build_pack = 'railpack';

    public int $port = 3000;

    public ?string $docker_compose_location = '/docker-compose.yaml';

    public function updatedBuildPack(): void {}

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
