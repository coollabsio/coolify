<?php

use App\Enums\ProcessStatus;
use App\Jobs\ApplicationPullRequestUpdateJob;
use App\Models\Application;
use App\Models\ApplicationPreview;

function previewLinks(Application $application, ApplicationPreview $preview): string
{
    $job = new ApplicationPullRequestUpdateJob($application, $preview, ProcessStatus::FINISHED);

    return (new ReflectionMethod($job, 'getPreviewLinks'))->invoke($job);
}

it('omits the container port from a compose preview link', function () {
    $application = new Application(['build_pack' => 'dockercompose']);
    $preview = new ApplicationPreview([
        'docker_compose_domains' => json_encode([
            'frontend' => ['domain' => 'https://136.example.com:3000'],
            'backend' => ['domain' => 'https://136.example.com:3001/backend'],
        ]),
    ]);

    expect(previewLinks($application, $preview))
        ->toBe('[Open frontend](https://136.example.com) | [Open backend](https://136.example.com/backend) | ');
});

it('omits the container port from a single preview link', function () {
    $application = new Application(['build_pack' => 'nixpacks']);
    $preview = new ApplicationPreview(['fqdn' => 'https://136.example.com:3000']);

    expect(previewLinks($application, $preview))->toBe('[Open Preview](https://136.example.com) | ');
});

it('leaves a preview link without a port untouched', function () {
    $application = new Application(['build_pack' => 'nixpacks']);
    $preview = new ApplicationPreview(['fqdn' => 'https://136.example.com/app']);

    expect(previewLinks($application, $preview))->toBe('[Open Preview](https://136.example.com/app) | ');
});
