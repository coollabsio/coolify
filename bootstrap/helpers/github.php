<?php

use App\Models\GithubApp;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

function generate_github_installation_token(GithubApp $source)
{
    $signingKey = InMemory::plainText($source->privateKey->private_key);
    $algorithm = new Sha256();
    $tokenBuilder = (new Builder(new JoseEncoder(), ChainedFormatter::default()));
    $now = new DateTimeImmutable();
    $now = $now->setTime($now->format('H'), $now->format('i'));
    $issuedToken = $tokenBuilder
        ->issuedBy($source->app_id)
        ->issuedAt($now)
        ->expiresAt($now->modify('+10 minutes'))
        ->getToken($algorithm, $signingKey)
        ->toString();
    $token = Http::withHeaders([
        'Authorization' => "Bearer $issuedToken",
        'Accept' => 'application/vnd.github.machine-man-preview+json'
    ])->post("{$source->api_url}/app/installations/{$source->installation_id}/access_tokens");
    if ($token->failed()) {
        throw new \Exception("Failed to get access token for " . $source->name . " with error: " . $token->json()['message']);
    }
    return $token->json()['token'];
}
function generate_github_jwt_token(GithubApp $source)
{
    $signingKey = InMemory::plainText($source->privateKey->private_key);
    $algorithm = new Sha256();
    $tokenBuilder = (new Builder(new JoseEncoder(), ChainedFormatter::default()));
    $now = new DateTimeImmutable();
    $now = $now->setTime($now->format('H'), $now->format('i'));
    $issuedToken = $tokenBuilder
        ->issuedBy($source->app_id)
        ->issuedAt($now->modify('-1 minute'))
        ->expiresAt($now->modify('+10 minutes'))
        ->getToken($algorithm, $signingKey)
        ->toString();
    return $issuedToken;
}
