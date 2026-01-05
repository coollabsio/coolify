<?php

use App\Rules\ValidGitRepositoryUrl;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

it('validates standard GitHub URLs', function () {
    $rule = new ValidGitRepositoryUrl;

    $validUrls = [
        'https://github.com/user/repo',
        'https://github.com/user/repo.git',
        'https://github.com/user/repo-with-dashes',
        'https://github.com/user/repo_with_underscores',
        'https://github.com/user/repo.with.dots',
        'https://github.com/organization/repository',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Failed for URL: {$url}");
    }
});

it('validates GitLab URLs', function () {
    $rule = new ValidGitRepositoryUrl;

    $validUrls = [
        'https://gitlab.com/user/repo',
        'https://gitlab.com/user/repo.git',
        'https://gitlab.com/organization/repository',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Failed for URL: {$url}");
    }
});

it('validates Bitbucket URLs', function () {
    $rule = new ValidGitRepositoryUrl;

    $validUrls = [
        'https://bitbucket.org/user/repo',
        'https://bitbucket.org/user/repo.git',
        'https://bitbucket.org/organization/repository',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Failed for URL: {$url}");
    }
});

it('validates tangled.sh URLs with @ symbol', function () {
    $rule = new ValidGitRepositoryUrl;

    $validUrls = [
        'https://tangled.org/@tangled.org/site',
        'https://tangled.org/@user/repo',
        'https://tangled.org/@organization/project',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Failed for URL: {$url}");
    }
});

it('validates SourceHut URLs with ~ symbol', function () {
    $rule = new ValidGitRepositoryUrl;

    $validUrls = [
        'https://git.sr.ht/~user/repo',
        'https://git.sr.ht/~user/project',
        'https://git.sr.ht/~organization/repository',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Failed for URL: {$url}");
    }
});

it('validates other Git hosting services', function () {
    $rule = new ValidGitRepositoryUrl;

    $validUrls = [
        'https://codeberg.org/user/repo',
        'https://codeberg.org/user/repo.git',
        'https://gitea.com/user/repo',
        'https://gitea.com/user/repo.git',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Failed for URL: {$url}");
    }
});

it('validates SSH URLs when allowed', function () {
    $rule = new ValidGitRepositoryUrl(allowSSH: true);

    $validUrls = [
        'git@github.com:user/repo.git',
        'git@gitlab.com:user/repo.git',
        'git@bitbucket.org:user/repo.git',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Failed for SSH URL: {$url}");
    }
});

it('rejects SSH URLs when not allowed', function () {
    $rule = new ValidGitRepositoryUrl(allowSSH: false);

    $invalidUrls = [
        'git@github.com:user/repo.git',
        'git@gitlab.com:user/repo.git',
    ];

    foreach ($invalidUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->fails())->toBeTrue("SSH URL should be rejected: {$url}");
        expect($validator->errors()->first('url'))->toContain('SSH URLs are not allowed');
    }
});

it('validates git:// protocol URLs', function () {
    $rule = new ValidGitRepositoryUrl;

    $validUrls = [
        'git://github.com/user/repo.git',
        'git://gitlab.com/user/repo.git',
        'git://git.sr.ht:~user/repo.git',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Failed for git:// URL: {$url}");
    }
});

it('rejects URLs with query parameters', function () {
    $rule = new ValidGitRepositoryUrl;

    $invalidUrls = [
        'https://github.com/user/repo?ref=main',
        'https://github.com/user/repo?token=abc123',
        'https://github.com/user/repo?utm_source=test',
    ];

    foreach ($invalidUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->fails())->toBeTrue("URL with query parameters should be rejected: {$url}");
        expect($validator->errors()->first('url'))->toContain('invalid characters');
    }
});

it('rejects URLs with fragments', function () {
    $rule = new ValidGitRepositoryUrl;

    $invalidUrls = [
        'https://github.com/user/repo#main',
        'https://github.com/user/repo#readme',
    ];

    foreach ($invalidUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->fails())->toBeTrue("URL with fragments should be rejected: {$url}");
        expect($validator->errors()->first('url'))->toContain('invalid characters');
    }
});

it('rejects internal/localhost URLs', function () {
    $rule = new ValidGitRepositoryUrl;

    $invalidUrls = [
        'https://localhost/user/repo',
        'https://127.0.0.1/user/repo',
        'https://0.0.0.0/user/repo',
        'https://::1/user/repo',
        'https://example.local/user/repo',
    ];

    foreach ($invalidUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->fails())->toBeTrue("Internal URL should be rejected: {$url}");
        $errorMessage = $validator->errors()->first('url');
        expect(in_array($errorMessage, [
            'The url cannot point to internal hosts.',
            'The url cannot use IP addresses.',
            'The url is not a valid URL.',
        ]))->toBeTrue("Unexpected error message: {$errorMessage}");
    }
});

it('rejects IP addresses when not allowed', function () {
    $rule = new ValidGitRepositoryUrl(allowIP: false);

    $invalidUrls = [
        'https://192.168.1.1/user/repo',
        'https://10.0.0.1/user/repo',
        'https://172.16.0.1/user/repo',
    ];

    foreach ($invalidUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->fails())->toBeTrue("IP address URL should be rejected: {$url}");
        expect($validator->errors()->first('url'))->toContain('IP addresses');
    }
});

it('allows IP addresses when explicitly allowed', function () {
    $rule = new ValidGitRepositoryUrl(allowIP: true);

    $validUrls = [
        'https://192.168.1.1/user/repo',
        'https://10.0.0.1/user/repo',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("IP address URL should be allowed: {$url}");
    }
});

it('rejects dangerous shell metacharacters', function () {
    $rule = new ValidGitRepositoryUrl;

    $dangerousChars = [';', '|', '&', '$', '`', '(', ')', '{', '}', '[', ']', '<', '>', '\n', '\r', '\0', '"', "'", '\\', '!', '?', '*', '^', '%', '=', '+', '#'];

    foreach ($dangerousChars as $char) {
        $url = "https://github.com/user/repo{$char}";
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->fails())->toBeTrue("URL with dangerous character '{$char}' should be rejected");
        expect($validator->errors()->first('url'))->toContain('invalid characters');
    }
});

it('rejects command substitution patterns', function () {
    $rule = new ValidGitRepositoryUrl;

    $dangerousPatterns = [
        'https://github.com/user/$(whoami)',
        'https://github.com/user/${USER}',
        'https://github.com/user;;',
        'https://github.com/user&&',
        'https://github.com/user||',
        'https://github.com/user>>',
        'https://github.com/user<<',
        'https://github.com/user\\n',
        'https://github.com/user../',
    ];

    foreach ($dangerousPatterns as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->fails())->toBeTrue("URL with dangerous pattern should be rejected: {$url}");
        $errorMessage = $validator->errors()->first('url');
        expect(in_array($errorMessage, [
            'The url contains invalid characters.',
            'The url contains invalid patterns.',
        ]))->toBeTrue("Unexpected error message: {$errorMessage}");
    }
});

it('rejects invalid URL formats', function () {
    $rule = new ValidGitRepositoryUrl;

    $invalidUrls = [
        'not-a-url',
        'ftp://github.com/user/repo',
        'file:///path/to/repo',
        'ssh://github.com/user/repo',
        'https://',
        'http://',
        'git@',
    ];

    foreach ($invalidUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->fails())->toBeTrue("Invalid URL format should be rejected: {$url}");
    }
});

it('accepts empty values', function () {
    $rule = new ValidGitRepositoryUrl;

    $validator = Validator::make(['url' => ''], ['url' => $rule]);
    expect($validator->passes())->toBeTrue('Empty URL should be accepted');

    $validator = Validator::make(['url' => null], ['url' => $rule]);
    expect($validator->passes())->toBeTrue('Null URL should be accepted');
});

it('validates complex repository paths', function () {
    $rule = new ValidGitRepositoryUrl;

    $validUrls = [
        'https://github.com/user/repo-with-many-dashes',
        'https://github.com/user/repo_with_many_underscores',
        'https://github.com/user/repo.with.many.dots',
        'https://github.com/user/repo@version',
        'https://github.com/user/repo~backup',
        'https://github.com/user/repo@version~backup',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Complex path should be valid: {$url}");
    }
});

it('validates nested repository paths', function () {
    $rule = new ValidGitRepositoryUrl;

    $validUrls = [
        'https://github.com/org/suborg/repo',
        'https://gitlab.com/group/subgroup/project',
        'https://tangled.org/@org/suborg/project',
        'https://git.sr.ht/~user/project/subproject',
    ];

    foreach ($validUrls as $url) {
        $validator = Validator::make(['url' => $url], ['url' => $rule]);
        expect($validator->passes())->toBeTrue("Nested path should be valid: {$url}");
    }
});

it('provides meaningful error messages', function () {
    $rule = new ValidGitRepositoryUrl;

    $testCases = [
        [
            'url' => 'https://github.com/user; rm -rf /',
            'expectedError' => 'invalid characters',
        ],
        [
            'url' => 'https://github.com/user/repo?token=secret',
            'expectedError' => 'invalid characters',
        ],
        [
            'url' => 'https://localhost/user/repo',
            'expectedError' => 'internal hosts',
        ],
    ];

    foreach ($testCases as $testCase) {
        $validator = Validator::make(['url' => $testCase['url']], ['url' => $rule]);
        expect($validator->fails())->toBeTrue("Should fail for: {$testCase['url']}");
        expect($validator->errors()->first('url'))->toContain($testCase['expectedError']);
    }
});
