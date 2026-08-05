<?php

use App\Enums\ProcessStatus;
use App\Jobs\ApplicationPullRequestUpdateJob;
use App\Models\Application;
use App\Models\ApplicationPreview;

function previewLinksFor(Application $application, ApplicationPreview $preview): string
{
    $job = new ApplicationPullRequestUpdateJob(
        application: $application,
        preview: $preview,
        status: ProcessStatus::FINISHED,
        deployment_uuid: 'deployment-uuid'
    );

    return (fn (): string => $this->getPreviewLinks())->call($job);
}

test('docker compose preview links strip internal ports and keep paths', function () {
    $application = new Application([
        'build_pack' => 'dockercompose',
    ]);

    $preview = new ApplicationPreview([
        'docker_compose_domains' => json_encode([
            'web' => [
                'domain' => 'https://136.example.com:3000,https://fallback.example.com:3001',
            ],
            'api' => [
                'domain' => 'https://136.example.com:3001/backend',
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    expect(previewLinksFor($application, $preview))
        ->toBe('[Open web](https://136.example.com) | [Open api](https://136.example.com/backend) | ');
});

test('single preview link strips internal port and keeps path', function () {
    $application = new Application([
        'build_pack' => 'nixpacks',
    ]);

    $preview = new ApplicationPreview([
        'fqdn' => 'https://136.example.com:3001/backend',
    ]);

    expect(previewLinksFor($application, $preview))
        ->toBe('[Open Preview](https://136.example.com/backend) | ');
});
