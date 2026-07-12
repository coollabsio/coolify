<?php

use App\Models\Server;

it('includes a uuid in standalone docker bootstrap attributes for the root server path', function () {
    $server = new Server;
    $server->id = 0;

    $attributes = $server->defaultStandaloneDockerAttributes(id: 0);

    expect($attributes)
        ->toMatchArray([
            'id' => 0,
            'name' => 'coolify',
            'network' => 'coolify',
            'server_id' => 0,
        ])
        ->and($attributes['uuid'])->toBeString()
        ->and($attributes['uuid'])->not->toBe('');
});
