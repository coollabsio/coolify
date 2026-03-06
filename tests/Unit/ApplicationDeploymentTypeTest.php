<?php

use App\Models\Application;

it('treats zero private key id as deploy key', function () {
    $application = new Application();
    $application->private_key_id = 0;
    $application->source = null;

    expect($application->deploymentType())->toBe('deploy_key');
});
