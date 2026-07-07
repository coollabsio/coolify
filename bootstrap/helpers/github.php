<?php

use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

/**
 * Extract and normalize the hostname from a GitHub URL.
 *
 * @param  string|null  $url  The URL to parse
 * @return string|null The lowercase hostname, or null if the URL is blank or has no parseable host
 */
function githubUrlHost(?string $url): ?string
{
    if (blank($url)) {
        return null;
    }

    $host = parse_url($url, PHP_URL_HOST);

    if (! is_string($host) || blank($host)) {
        return null;
    }

    return strtolower($host);
}

/**
 * Build the scheme://host[:port] origin for a GitHub URL.
 *
 * This helper fails explicitly for blank, scheme-less, or malformed input when
 * githubUrlHost() cannot parse a host, because returning the original input
 * would not be a valid origin. Callers should pass already-validated URLs.
 *
 * @param  string  $url  The URL to derive the origin from
 * @return string The normalized origin
 *
 * @throws InvalidArgumentException When the URL does not contain a parseable scheme and host
 */
function githubUrlOrigin(string $url): string
{
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $host = githubUrlHost($url);
    $port = parse_url($url, PHP_URL_PORT);

    if (! is_string($scheme) || blank($scheme) || ! $host) {
        throw new InvalidArgumentException('GitHub URL must include a valid scheme and host.');
    }

    return $scheme.'://'.$host.($port ? ":{$port}" : '');
}

/**
 * Determine whether the URL points at github.com.
 *
 * @param  string|null  $htmlUrl  The GitHub HTML URL to check
 */
function isGithubDotComHost(?string $htmlUrl): bool
{
    return githubUrlHost($htmlUrl) === 'github.com';
}

/**
 * Determine whether the URL points at a *.ghe.com GitHub Enterprise Cloud host.
 *
 * @param  string|null  $htmlUrl  The GitHub HTML URL to check
 */
function isGheDotComHost(?string $htmlUrl): bool
{
    $host = githubUrlHost($htmlUrl);

    return is_string($host)
        && Str::endsWith($host, '.ghe.com')
        && ! Str::startsWith($host, 'api.');
}

/**
 * Determine whether the URL belongs to GitHub's cloud family (github.com or *.ghe.com).
 *
 * @param  string|null  $htmlUrl  The GitHub HTML URL to check
 */
function isGithubCloudFamilyHost(?string $htmlUrl): bool
{
    return isGithubDotComHost($htmlUrl) || isGheDotComHost($htmlUrl);
}

/**
 * Determine whether the URL belongs to a self-hosted GitHub Enterprise Server.
 *
 * @param  string|null  $htmlUrl  The GitHub HTML URL to check
 */
function isGithubEnterpriseServerHost(?string $htmlUrl): bool
{
    return filled($htmlUrl) && ! isGithubCloudFamilyHost($htmlUrl);
}

/**
 * Derive the GitHub REST API base URL from a GitHub HTML URL.
 *
 * @param  string  $htmlUrl  The GitHub HTML URL
 * @return string The API base URL (api.github.com, api.<host> for *.ghe.com, or <origin>/api/v3 for GHES)
 */
function githubApiUrlFromHtmlUrl(string $htmlUrl): string
{
    if (isGithubDotComHost($htmlUrl)) {
        return 'https://api.github.com';
    }

    if (isGheDotComHost($htmlUrl)) {
        return 'https://api.'.githubUrlHost($htmlUrl);
    }

    return githubUrlOrigin($htmlUrl).'/api/v3';
}

/**
 * Normalize a GitHub organization slug by trimming surrounding slashes and whitespace.
 *
 * @param  string|null  $organization  The raw organization value
 * @return string|null The trimmed organization, or null when blank
 */
function normalizeGithubOrganization(?string $organization): ?string
{
    if (blank($organization)) {
        return null;
    }

    return trim((string) $organization, "/ \t\n\r\0\x0B");
}

/**
 * URL-encode a single GitHub path segment.
 *
 * @param  string  $segment  The raw path segment
 * @return string The raw-URL-encoded segment
 */
function encodeGithubPathSegment(string $segment): string
{
    return rawurlencode($segment);
}

function assertGithubClockInSync(string $apiUrl): void
{
    $response = Http::get("{$apiUrl}/zen");
    $serverTime = CarbonImmutable::now()->setTimezone('UTC');
    $githubTime = Carbon::parse($response->header('date'));
    $timeDiff = abs($serverTime->diffInSeconds($githubTime));

    if ($timeDiff > 50) {
        throw new Exception(
            'System time is out of sync with GitHub API time:<br>'.
            '- System time: '.$serverTime->format('Y-m-d H:i:s').' UTC<br>'.
            '- GitHub time: '.$githubTime->format('Y-m-d H:i:s').' UTC<br>'.
            '- Difference: '.$timeDiff.' seconds<br>'.
            'Please synchronize your system clock.'
        );
    }
}

function generateGithubToken(GithubApp $source, string $type)
{
    assertGithubClockInSync($source->api_url);

    $signingKey = InMemory::plainText($source->privateKey->private_key);
    $algorithm = new Sha256;
    $tokenBuilder = (new Builder(new JoseEncoder, ChainedFormatter::default()));
    $now = CarbonImmutable::now()->setTimezone('UTC');
    $now = $now->setTime($now->format('H'), $now->format('i'), $now->format('s'));

    $jwt = $tokenBuilder
        ->issuedBy($source->app_id)
        ->issuedAt($now->modify('-1 minute'))
        ->expiresAt($now->modify('+8 minutes'))
        ->getToken($algorithm, $signingKey)
        ->toString();

    return match ($type) {
        'jwt' => $jwt,
        'installation' => (function () use ($source, $jwt) {
            $response = Http::withHeaders([
                'Authorization' => "Bearer $jwt",
                'Accept' => 'application/vnd.github.machine-man-preview+json',
            ])->post("{$source->api_url}/app/installations/{$source->installation_id}/access_tokens");

            if (! $response->successful()) {
                $error = data_get($response->json(), 'message', 'no error message found');
                if ($error === 'Not Found') {
                    $error = 'Repository not found. Is it moved or deleted?';
                }
                throw new RuntimeException("Failed to get installation token for {$source->name} with error: ".$error);
            }

            return $response->json()['token'];
        })(),
        default => throw new InvalidArgumentException("Unsupported token type: {$type}")
    };
}

function generateGithubInstallationToken(GithubApp $source)
{
    return generateGithubToken($source, 'installation');
}

function generateGithubJwt(GithubApp $source)
{
    return generateGithubToken($source, 'jwt');
}

function githubApi(GithubApp|GitlabApp|null $source, string $endpoint, string $method = 'get', ?array $data = null, bool $throwError = true)
{
    if (is_null($source)) {
        throw new Exception('Source is required for API calls');
    }

    if ($source->getMorphClass() !== GithubApp::class) {
        throw new InvalidArgumentException("Unsupported source type: {$source->getMorphClass()}");
    }

    if ($source->is_public) {
        $response = Http::GitHub($source->api_url)->$method($endpoint);
    } else {
        $token = generateGithubInstallationToken($source);
        if ($data && in_array(strtolower($method), ['post', 'patch', 'put'])) {
            $response = Http::GitHub($source->api_url, $token)->$method($endpoint, $data);
        } else {
            $response = Http::GitHub($source->api_url, $token)->$method($endpoint);
        }
    }

    if (! $response->successful() && $throwError) {
        $resetTime = Carbon::parse((int) $response->header('X-RateLimit-Reset'))->format('Y-m-d H:i:s');
        $errorMessage = data_get($response->json(), 'message', 'no error message found');
        $remainingCalls = $response->header('X-RateLimit-Remaining', '0');

        throw new Exception(
            'GitHub API call failed:<br>'.
            "Error: {$errorMessage}<br>".
            'Rate Limit Status:<br>'.
            "- Remaining Calls: {$remainingCalls}<br>".
            "- Reset Time: {$resetTime} UTC"
        );
    }

    return [
        'rate_limit_remaining' => $response->header('X-RateLimit-Remaining'),
        'rate_limit_reset' => $response->header('X-RateLimit-Reset'),
        'data' => collect($response->json()),
    ];
}

function generateGithubAppJwt(string $privateKey, string|int $appId): string
{
    $algorithm = new Sha256;
    $tokenBuilder = (new Builder(new JoseEncoder, ChainedFormatter::default()));
    $now = CarbonImmutable::now()->setTimezone('UTC');
    $now = $now->setTime($now->format('H'), $now->format('i'), $now->format('s'));

    return $tokenBuilder
        ->issuedBy((string) $appId)
        ->issuedAt($now->modify('-1 minute'))
        ->expiresAt($now->modify('+8 minutes'))
        ->getToken($algorithm, InMemory::plainText($privateKey))
        ->toString();
}

function syncGithubAppName(GithubApp $source, bool $throw = false): ?string
{
    try {
        if (blank($source->app_id) || blank($source->private_key_id)) {
            return null;
        }

        $privateKey = $source->privateKey ?: PrivateKey::find($source->private_key_id);

        if (! $privateKey) {
            return null;
        }

        assertGithubClockInSync($source->api_url);

        $jwt = generateGithubAppJwt($privateKey->private_key, $source->app_id);

        $response = Http::withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Authorization' => "Bearer {$jwt}",
        ])->get("{$source->api_url}/app");

        if (! $response->successful()) {
            throw new RuntimeException(data_get($response->json(), 'message', 'Failed to fetch GitHub App information.'));
        }

        $appSlug = data_get($response->json(), 'slug');

        if (blank($appSlug)) {
            return null;
        }

        $source->name = $appSlug;

        if ($source->exists) {
            $source->save();
        }

        $privateKey->name = "github-app-{$appSlug}";
        $privateKey->save();

        return $appSlug;
    } catch (Throwable $e) {
        if ($throw) {
            throw $e;
        }

        return null;
    }
}

function getInstallationPath(GithubApp $source): string
{
    $name = encodeGithubPathSegment(Str::kebab($source->name));
    $state = Str::random(64);
    $organization = normalizeGithubOrganization($source->organization);

    if (isGithubEnterpriseServerHost($source->html_url)) {
        $path = "github-apps/{$name}";
    } elseif (isGheDotComHost($source->html_url) && filled($organization)) {
        $path = 'apps/'.encodeGithubPathSegment($organization)."/{$name}";
    } else {
        $path = "apps/{$name}";
    }

    Cache::put('github-app-setup-state:'.hash('sha256', $state), [
        'action' => 'install',
        'github_app_id' => $source->id,
        'team_id' => $source->team_id,
    ], now()->addMinutes(60));

    return rtrim($source->html_url, '/')."/{$path}/installations/new?".http_build_query(['state' => $state]);
}

function getPermissionsPath(GithubApp $source)
{
    $name = encodeGithubPathSegment(Str::kebab($source->name));
    $organization = normalizeGithubOrganization($source->organization);

    if (filled($organization)) {
        return rtrim($source->html_url, '/').'/organizations/'.encodeGithubPathSegment($organization)."/settings/apps/{$name}/permissions";
    }

    return rtrim($source->html_url, '/')."/settings/apps/{$name}/permissions";
}

function loadRepositoryByPage(GithubApp $source, string $token, int $page)
{
    $response = Http::GitHub($source->api_url, $token)
        ->timeout(20)
        ->retry(3, 200, throw: false)
        ->get('/installation/repositories', [
            'per_page' => 100,
            'page' => $page,
        ]);
    $json = $response->json();
    if ($response->status() !== 200) {
        return [
            'total_count' => 0,
            'repositories' => [],
        ];
    }

    if ($json['total_count'] === 0) {
        return [
            'total_count' => 0,
            'repositories' => [],
        ];
    }

    return [
        'total_count' => $json['total_count'],
        'repositories' => $json['repositories'],
    ];
}
function getGithubCommitRangeFiles(?GithubApp $source, string $owner, string $repo, string $beforeSha, string $afterSha): array
{
    try {
        if (! $source) {
            // Manual webhooks don't have GitHub App authentication
            // Return empty array so watch paths are ignored (current behavior)
            return [];
        }

        $endpoint = "/repos/{$owner}/{$repo}/compare/{$beforeSha}...{$afterSha}";
        $response = githubApi($source, $endpoint, 'get', null, false);

        if (! $response) {
            return [];
        }

        $files = collect(data_get($response, 'data.files', []));

        return $files->pluck('filename')->filter()->values()->toArray();
    } catch (Exception $e) {

        return [];
    }
}

function getGithubPullRequestFiles(?GithubApp $source, string $owner, string $repo, int $pullRequestId): array
{
    try {
        if (! $source) {
            // Manual webhooks don't have GitHub App authentication
            // Return empty array so watch paths are ignored (current behavior)
            return [];
        }

        $endpoint = "/repos/{$owner}/{$repo}/pulls/{$pullRequestId}/files";
        $response = githubApi($source, $endpoint, 'get', null, false);

        if (! $response) {
            return [];
        }

        $files = collect(data_get($response, 'data', []));

        return $files->pluck('filename')->filter()->values()->toArray();
    } catch (Exception $e) {

        return [];
    }
}
