<?php

it('redacts oauth2 tokens from deployment logs', function () {
    $fakeOAuthToken = str_repeat('a', 30); // ggignore
    $text = "git clone https://oauth2:{$fakeOAuthToken}@gitlab.example.com/group/repo.git /app";
    $result = remove_iip($text);

    expect($result)->not->toContain($fakeOAuthToken);
    expect($result)->toContain('oauth2:');
});

it('redacts x-access-token from logs', function () {
    $fakeToken = str_repeat('x', 40); // ggignore
    $text = "git clone https://x-access-token:{$fakeToken}@github.com/org/repo.git /app";
    $result = remove_iip($text);

    expect($result)->not->toContain($fakeToken);
    expect($result)->toContain('x-access-token:');
});

it('redacts gitlab personal access tokens', function () {
    $fakeToken = 'glpat-'.str_repeat('y', 20); // ggignore
    $text = "Authorization: Bearer {$fakeToken}";
    $result = remove_iip($text);

    expect($result)->not->toContain($fakeToken);
});
