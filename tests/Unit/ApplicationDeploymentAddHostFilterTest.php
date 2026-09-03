<?php

use App\Jobs\ApplicationDeploymentJob;

it('skips the current coolify-helper named after the deployment uuid', function () {
    $helper = 'aj0rh799df7pdo4rgtqb36ed';

    expect(ApplicationDeploymentJob::shouldSkipBuildAddHost($helper, $helper))->toBeTrue();
});

it('keeps a consistently named application container that is not the helper', function () {
    $helper = 'aj0rh799df7pdo4rgtqb36ed';
    $appUuid = 'fqa2thkgr99abcdabcdabcx';

    expect(ApplicationDeploymentJob::shouldSkipBuildAddHost($appUuid, $helper))->toBeFalse()
        ->and(ApplicationDeploymentJob::shouldSkipBuildAddHost('api-'.$appUuid, $helper))->toBeFalse();
});

it('still skips the proxy and rolling-update timestamped names', function () {
    $helper = 'aj0rh799df7pdo4rgtqb36ed';

    expect(ApplicationDeploymentJob::shouldSkipBuildAddHost('coolify-proxy', $helper))->toBeTrue()
        ->and(ApplicationDeploymentJob::shouldSkipBuildAddHost('fqa2thkgr99abcdabcdabcx-072221650268', $helper))->toBeTrue()
        ->and(ApplicationDeploymentJob::shouldSkipBuildAddHost(null, $helper))->toBeTrue();
});
