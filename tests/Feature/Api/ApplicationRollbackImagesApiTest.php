<?php

use App\Actions\Application\GetRollbackImages;

it('extracts the current rollback image tag when the registry includes a port', function () {
    expect(GetRollbackImages::imageTag('registry.example.com:5000/team/application:commit-sha'))
        ->toBe('commit-sha');
});

it('does not treat a digest as the current rollback image tag', function () {
    expect(GetRollbackImages::imageTag('registry.example.com:5000/team/application@sha256:'.str_repeat('a', 64)))
        ->toBeNull();
});
