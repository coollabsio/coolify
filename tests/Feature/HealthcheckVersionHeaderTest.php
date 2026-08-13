<?php

use App\Http\Controllers\Api\OtherController;
use Illuminate\Http\Request;

it('adds the running version header on the healthcheck response', function () {
    config(['constants.coolify.version' => '4.3.1']);

    $response = (new OtherController)->healthcheck(Request::create('/api/health', 'GET'));

    expect($response->getContent())->toBe('OK')
        ->and($response->headers->get('X-Coolify-Version'))->toBe('4.3.1');
});

it('exposes the running Coolify version on the public health endpoint', function () {
    config(['constants.coolify.version' => '4.3.1']);

    $this->get('/api/health')
        ->assertSuccessful()
        ->assertSee('OK')
        ->assertHeader('X-Coolify-Version', '4.3.1');
});
