<?php

use App\Jobs\CheckTraefikVersionJob;

it('has correct retry configuration', function () {
    $job = new CheckTraefikVersionJob;

    expect($job->tries)->toBe(3);
});
