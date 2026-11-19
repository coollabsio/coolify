<?php

use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use Symfony\Component\Yaml\Yaml;

it('does not use the removed upstream MinIO images in service templates', function () {
    $removedImages = ['minio/minio', 'minio/mc', 'quay.io/minio/minio', 'quay.io/minio/mc', 'docker.io/minio/minio', 'docker.io/minio/mc'];
    $offenders = [];

    foreach (glob(dirname(__DIR__, 2).'/templates/compose/*.yaml') as $templatePath) {
        $services = Yaml::parse(file_get_contents($templatePath))['services'] ?? [];

        foreach ($services as $serviceName => $service) {
            $imageName = str($service['image'] ?? '')->before(':')->before('@')->value();

            if (in_array($imageName, $removedImages, true)) {
                $offenders[] = basename($templatePath).": {$serviceName} uses {$service['image']}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('treats MinIO-compatible images as MinIO services', function (string $image) {
    $service = new Service;
    $service->setRelation('server', new Server);
    $service->setRelation('environment_variables', collect([
        (new EnvironmentVariable)->forceFill(['key' => 'MINIO_BROWSER_REDIRECT_URL', 'value' => 'https://console.example.com']),
        (new EnvironmentVariable)->forceFill(['key' => 'MINIO_SERVER_URL', 'value' => 'https://s3.example.com']),
    ]));

    $application = (new ServiceApplication)->setRawAttributes(['image' => $image], sync: true);
    $application->setRelation('service', $service);

    expect($application->serviceType())->not->toBeNull()
        ->and(generateServiceSpecificFqdns($application)->all())->toBe([
            'https://console.example.com:9001',
            'https://s3.example.com:9000',
        ]);
})->with([
    'pgsty/silo:RELEASE.2026-10-15T00-00-00Z',
    'docker.io/pgsty/silo:RELEASE.2026-10-15T00-00-00Z',
    'ghcr.io/coollabsio/minio:RELEASE.2025-10-15T17-29-55Z',
]);
