<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;

it('should return the application instance', function () {
    $application = Application::factory()->create();

    $deployment = ApplicationDeploymentQueue::factory()->create([
        'application_id' => $application->id,
    ]);

    expect($deployment->application->id)
        ->toBe($application->id)
        ->and($deployment->application->name)
        ->toBe($application->name);

});
