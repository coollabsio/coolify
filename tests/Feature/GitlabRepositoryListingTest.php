<?php

use App\Models\GitlabApp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Model::encryptUsing(new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
});

afterEach(function () {
    Model::encryptUsing(null);
});

it('returns a stable shape with has_more false when GitLab repo listing fails', function () {
    Http::fake(['*' => Http::response(['message' => '401 Unauthorized'], 401)]);

    $source = new GitlabApp([
        'api_url' => 'https://gitlab.example.test/api/v4',
        'access_token' => str_repeat('t', 20), // ggignore
        'refresh_token' => str_repeat('r', 20), // ggignore
        'expires_at' => time() + 3600,
    ]);

    $result = loadGitlabRepositories($source);

    expect($result)->toHaveKeys(['total_count', 'has_more', 'repositories']);
    expect($result['has_more'])->toBeFalse();
    expect($result['repositories'])->toBe([]);
});

it('limits GitLab repositories to the exact group and its descendants', function () {
    Http::fake(['*' => Http::response([
        ['id' => 1, 'name' => 'a', 'path_with_namespace' => 'team/a', 'namespace' => ['full_path' => 'team', 'kind' => 'group']],
        ['id' => 2, 'name' => 'b', 'path_with_namespace' => 'team/sub/b', 'namespace' => ['full_path' => 'team/sub', 'kind' => 'group']],
        ['id' => 3, 'name' => 'c', 'path_with_namespace' => 'team-secret/c', 'namespace' => ['full_path' => 'team-secret', 'kind' => 'group']],
    ], 200)]);

    $source = new GitlabApp([
        'api_url' => 'https://gitlab.example.test/api/v4',
        'access_token' => str_repeat('t', 20), // ggignore
        'refresh_token' => str_repeat('r', 20), // ggignore
        'expires_at' => time() + 3600,
        'group_name' => 'team',
    ]);

    $paths = collect(loadGitlabRepositories($source)['repositories'])->pluck('path_with_namespace')->all();

    expect($paths)->toContain('team/a', 'team/sub/b');
    expect($paths)->not->toContain('team-secret/c');
});
