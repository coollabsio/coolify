<?php

use App\Models\Server;
use App\Support\ValidationPatterns;

it('provides shared validation rules for SSH usernames', function () {
    expect(ValidationPatterns::SERVER_USERNAME_PATTERN)->toBe('/^[a-zA-Z0-9._-]+$/');
    expect(ValidationPatterns::serverUsernameRules())->toContain('regex:'.ValidationPatterns::SERVER_USERNAME_PATTERN);

    expect(preg_match(ValidationPatterns::SERVER_USERNAME_PATTERN, 'deploy.user'))->toBe(1);
    expect(preg_match(ValidationPatterns::SERVER_USERNAME_PATTERN, 'deploy$user'))->toBe(0);
});

it('preserves dots when sanitizing server SSH usernames', function () {
    $server = new Server;
    $server->user = 'deploy.user';

    expect($server->user)->toBe('deploy.user');
});
