<?php

use App\Models\GithubApp;
use App\Models\GitlabApp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

function generate_github_installation_token(GithubApp $source)
{
    $signingKey = InMemory::plainText($source->privateKey->private_key);
    $algorithm = new Sha256;
    $tokenBuilder = (new Builder(new JoseEncoder, ChainedFormatter::default()));
    $now = new DateTimeImmutable;
    $now = $now->setTime($now->format('H'), $now->format('i'));
    $issuedToken = $tokenBuilder
        ->issuedBy($source->app_id)
        ->issuedAt($now)
        ->expiresAt($now->modify('+10 minutes'))
        ->getToken($algorithm, $signingKey)
        ->toString();
    $token = Http::withHeaders([
        'Authorization' => "Bearer $issuedToken",
        'Accept' => 'application/vnd.github.machine-man-preview+json',
    ])->post("{$source->api_url}/app/installations/{$source->installation_id}/access_tokens");
    if ($token->failed()) {
        throw new RuntimeException('Failed to get access token for '.$source->name.' with error: '.data_get($token->json(), 'message', 'no error message found'));
    }

    return $token->json()['token'];
}

function generate_github_jwt_token(GithubApp $source)
{
    $signingKey = InMemory::plainText($source->privateKey->private_key);
    $algorithm = new Sha256;
    $tokenBuilder = (new Builder(new JoseEncoder, ChainedFormatter::default()));
    $now = new DateTimeImmutable;
    $now = $now->setTime($now->format('H'), $now->format('i'));
    $issuedToken = $tokenBuilder
        ->issuedBy($source->app_id)
        ->issuedAt($now->modify('-1 minute'))
        ->expiresAt($now->modify('+10 minutes'))
        ->getToken($algorithm, $signingKey)
        ->toString();

    return $issuedToken;
}

function githubApi(GithubApp|GitlabApp|null $source, string $endpoint, string $method = 'get', ?array $data = null, bool $throwError = true)
{
    if (is_null($source)) {
        throw new \Exception('Not implemented yet.');
    }
    if ($source->getMorphClass() == 'App\Models\GithubApp') {
        if ($source->is_public) {
            $response = Http::github($source->api_url)->$method($endpoint);
        } else {
            $github_access_token = generate_github_installation_token($source);
            if ($data && ($method === 'post' || $method === 'patch' || $method === 'put')) {
                $response = Http::github($source->api_url, $github_access_token)->$method($endpoint, $data);
            } else {
                $response = Http::github($source->api_url, $github_access_token)->$method($endpoint);
            }
        }
    }
    $json = $response->json();
    if ($response->failed() && $throwError) {
        ray($json);
        throw new \Exception("Failed to get data from {$source->name} with error:<br><br>".$json['message'].'<br><br>Rate Limit resets at: '.Carbon::parse((int) $response->header('X-RateLimit-Reset'))->format('Y-m-d H:i:s').'UTC');
    }

    return [
        'rate_limit_remaining' => $response->header('X-RateLimit-Remaining'),
        'rate_limit_reset' => $response->header('X-RateLimit-Reset'),
        'data' => collect($json),
    ];
}

function get_installation_path(GithubApp $source)
{
    $github = GithubApp::where('uuid', $source->uuid)->first();
    $name = str(Str::kebab($github->name));
    $installation_path = $github->html_url === 'https://github.com' ? 'apps' : 'github-apps';

    return "$github->html_url/$installation_path/$name/installations/new";
}
function get_permissions_path(GithubApp $source)
{
    $github = GithubApp::where('uuid', $source->uuid)->first();
    $name = str(Str::kebab($github->name));

    return "$github->html_url/settings/apps/$name/permissions";
}
