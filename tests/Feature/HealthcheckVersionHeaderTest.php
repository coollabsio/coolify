<?php

use App\Http\Controllers\Api\OtherController;
use Illuminate\Http\Request;

it('does not expose the running version on the healthcheck response', function () {
    config(['constants.coolify.version' => '4.3.1']);

    $response = (new OtherController)->healthcheck(Request::create('/api/health', 'GET'));

    expect($response->getContent())->toBe('OK')
        ->and($response->headers->has('X-Coolify-Version'))->toBeFalse();
});

it('does not expose the running Coolify version on the public health endpoint', function () {
    config(['constants.coolify.version' => '4.3.1']);

    $this->get('/api/health')
        ->assertSuccessful()
        ->assertSee('OK')
        ->assertHeaderMissing('X-Coolify-Version');
});
