<?php

use App\Models\GithubApp;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

it('classifies github hosts', function () {
    expect(isGithubDotComHost('https://github.com'))->toBeTrue()
        ->and(isGheDotComHost('https://octocorp.ghe.com'))->toBeTrue()
        ->and(isGithubCloudFamilyHost('https://octocorp.ghe.com'))->toBeTrue()
        ->and(isGithubCloudFamilyHost('https://github.com'))->toBeTrue()
        ->and(isGithubEnterpriseServerHost('https://github.company.internal'))->toBeTrue()
        ->and(isGithubEnterpriseServerHost('https://octocorp.ghe.com'))->toBeFalse();
});

it('derives github api urls from html urls', function (string $htmlUrl, string $apiUrl) {
    expect(githubApiUrlFromHtmlUrl($htmlUrl))->toBe($apiUrl);
})->with([
    'github.com' => ['https://github.com', 'https://api.github.com'],
    'ghe.com data residency' => ['https://octocorp.ghe.com', 'https://api.octocorp.ghe.com'],
    'github enterprise server' => ['https://github.company.internal', 'https://github.company.internal/api/v3'],
]);

it('rejects malformed urls when deriving an origin', function (string $url) {
    expect(fn () => githubUrlOrigin($url))->toThrow(InvalidArgumentException::class);
})->with([
    'blank' => [''],
    'scheme-less' => ['github.company.internal'],
    'malformed' => ['not-a-url'],
]);

it('generates correct install paths for github cloud ghe cloud and ghes', function (array $attributes, string $expectedPrefix) {
    $githubApp = new GithubApp;
    $githubApp->forceFill(array_merge([
        'id' => 123,
        'name' => 'Coolify Test App',
        'team_id' => 456,
    ], $attributes));

    $installationUrl = getInstallationPath($githubApp);
    parse_str(parse_url($installationUrl, PHP_URL_QUERY), $query);
    $state = $query['state'] ?? null;

    expect($installationUrl)->toStartWith($expectedPrefix)
        ->and($state)->not->toBeEmpty()
        ->and(Cache::get('github-app-setup-state:'.hash('sha256', $state)))
        ->toMatchArray([
            'action' => 'install',
            'github_app_id' => 123,
            'team_id' => 456,
        ]);
})->with([
    'github.com' => [
        ['html_url' => 'https://github.com'],
        'https://github.com/apps/coolify-test-app/installations/new?',
    ],
    'ghe.com organization' => [
        ['html_url' => 'https://octocorp.ghe.com', 'organization' => 'octo-corp'],
        'https://octocorp.ghe.com/apps/octo-corp/coolify-test-app/installations/new?',
    ],
    'ghe.com blank organization fallback' => [
        ['html_url' => 'https://octocorp.ghe.com', 'organization' => null],
        'https://octocorp.ghe.com/apps/coolify-test-app/installations/new?',
    ],
    'github enterprise server' => [
        ['html_url' => 'https://github.company.internal', 'organization' => 'octo-corp'],
        'https://github.company.internal/github-apps/coolify-test-app/installations/new?',
    ],
]);

it('encodes organization path segments in settings links', function () {
    $githubApp = new GithubApp;
    $githubApp->forceFill([
        'name' => 'coolify-app',
        'organization' => 'octo+corp',
        'api_url' => 'https://api.octocorp.ghe.com',
        'html_url' => 'https://octocorp.ghe.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => 123,
    ]);

    expect(getPermissionsPath($githubApp))->toBe('https://octocorp.ghe.com/organizations/octo%2Bcorp/settings/apps/coolify-app/permissions');
});
