<?php

use App\Models\GithubApp;
use App\Models\GitlabApp;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

function generateGithubToken(GithubApp $source, string $type)
{
    $response = Http::get("{$source->api_url}/zen");
    $serverTime = CarbonImmutable::now()->setTimezone('UTC');
    $githubTime = Carbon::parse($response->header('date'));
    $timeDiff = abs($serverTime->diffInSeconds($githubTime));

    if ($timeDiff > 50) {
        throw new \Exception(
            'System time is out of sync with GitHub API time:<br>'.
            '- System time: '.$serverTime->format('Y-m-d H:i:s').' UTC<br>'.
            '- GitHub time: '.$githubTime->format('Y-m-d H:i:s').' UTC<br>'.
            '- Difference: '.$timeDiff.' seconds<br>'.
            'Please synchronize your system clock.'
        );
    }

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
                throw new RuntimeException("Failed to get installation token for {$source->name} with error: ".$error);
            }

            return $response->json()['token'];
        })(),
        default => throw new \InvalidArgumentException("Unsupported token type: {$type}")
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
        throw new \Exception('Source is required for API calls');
    }

    if ($source->getMorphClass() !== GithubApp::class) {
        throw new \InvalidArgumentException("Unsupported source type: {$source->getMorphClass()}");
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

        throw new \Exception(
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

function getInstallationPath(GithubApp $source)
{
    $github = GithubApp::where('uuid', $source->uuid)->first();
    $name = str(Str::kebab($github->name));
    $installation_path = $github->html_url === 'https://github.com' ? 'apps' : 'github-apps';

    return "$github->html_url/$installation_path/$name/installations/new";
}

function getPermissionsPath(GithubApp $source)
{
    $github = GithubApp::where('uuid', $source->uuid)->first();
    $name = str(Str::kebab($github->name));

    return "$github->html_url/settings/apps/$name/permissions";
}
