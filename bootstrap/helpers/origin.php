<?php

use App\Models\OriginApp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;

/*
 * Cursor Origin Apps: https://cursor.com/docs/api/origin/llms-full.txt
 * App JWT (EdDSA) -> installation token (oit_) -> REST API and git over HTTPS (x-access-token).
 */

const ORIGIN_ED25519_PKCS8_PREFIX = '302e020100300506032b657004220420';

const ORIGIN_ED25519_SPKI_PREFIX = '302a300506032b6570032100';

/**
 * Scopes Coolify requests on install: clone/fetch and read pull requests for previews.
 */
const ORIGIN_APP_SCOPES = ['repository:contents:read', 'repository:pull_requests:read'];

/**
 * Repository events Coolify subscribes to. installation.* events are always delivered.
 */
const ORIGIN_APP_EVENTS = [
    'repository.pushed',
    'pull_request.created',
    'pull_request.head_ref.pushed',
    'pull_request.reopened',
    'pull_request.published',
    'pull_request.closed',
    'pull_request.merged',
];

/**
 * @return array{private_key: string, public_key: string} PKCS#8 private key and SPKI public key, both PEM.
 */
function generateOriginKeyPair(): array
{
    $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
    $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed));

    return [
        'private_key' => originPem('PRIVATE KEY', hex2bin(ORIGIN_ED25519_PKCS8_PREFIX).$seed),
        'public_key' => originPem('PUBLIC KEY', hex2bin(ORIGIN_ED25519_SPKI_PREFIX).$publicKey),
    ];
}

function originPem(string $label, string $der): string
{
    return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
}

/**
 * Convert an Ed25519 PKCS#8 PEM private key to the 64-byte libsodium secret key.
 */
function originSecretKey(string $privateKeyPem): string
{
    $body = preg_replace('/-----(BEGIN|END) PRIVATE KEY-----|\s+/', '', $privateKeyPem);
    $der = base64_decode((string) $body, true);
    $prefix = hex2bin(ORIGIN_ED25519_PKCS8_PREFIX);

    if ($der === false || strlen($der) !== strlen($prefix) + SODIUM_CRYPTO_SIGN_SEEDBYTES || ! str_starts_with($der, $prefix)) {
        throw new RuntimeException('The Origin App private key is not a valid Ed25519 PKCS#8 key.');
    }

    return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(substr($der, strlen($prefix))));
}

function generateOriginAppJwt(OriginApp $source): string
{
    if (blank($source->app_id) || blank($source->private_key)) {
        throw new RuntimeException('The Origin App ID or private key is missing.');
    }

    $now = CarbonImmutable::now('UTC')->startOfSecond();

    return (new Builder(new JoseEncoder, ChainedFormatter::default()))
        ->withHeader('kid', $source->app_id)
        ->issuedBy($source->app_id)
        ->permittedFor('origin-apps')
        ->issuedAt($now->subSeconds(30))
        ->expiresAt($now->addSeconds(270))
        ->getToken(new Eddsa, InMemory::plainText(originSecretKey($source->private_key)))
        ->toString();
}

function originHttp(OriginApp $source, string $bearerToken)
{
    return Http::GitSource($source->api_url)
        ->baseUrl(rtrim($source->api_url, '/'))
        ->acceptJson()
        ->withToken($bearerToken)
        ->timeout(20);
}

function originResponseError($response): string
{
    return (string) (data_get($response->json(), 'message') ?? data_get($response->json(), 'error.message') ?? $response->body());
}

/**
 * Mint a fresh installation token. Tokens live five minutes or less, so mint one right before each use.
 */
function generateOriginInstallationToken(OriginApp $source): string
{
    if (blank($source->installation_id)) {
        throw new RuntimeException("Origin App {$source->name} is not installed yet.");
    }

    $response = originHttp($source, generateOriginAppJwt($source))
        ->post('/app/installations/'.rawurlencode($source->installation_id).'/access_tokens', (object) []);

    if (! $response->successful()) {
        throw new RuntimeException("Failed to get an Origin installation token for {$source->name}: ".originResponseError($response));
    }

    $token = data_get($response->json(), 'token');
    if (! is_string($token) || $token === '') {
        throw new RuntimeException("Origin returned no installation token for {$source->name}.");
    }

    return $token;
}

/**
 * Call the Origin API with an installation token (or with the app JWT when $asApp is true).
 */
function originApi(OriginApp $source, string $endpoint, string $method = 'get', array $data = [], bool $asApp = false): array
{
    $token = $asApp ? generateOriginAppJwt($source) : generateOriginInstallationToken($source);
    $response = originHttp($source, $token)->{strtolower($method)}($endpoint, $data);

    if (! $response->successful()) {
        throw new RuntimeException('Origin API call failed: '.originResponseError($response));
    }

    return $response->json() ?? [];
}

/**
 * @return array<int, array{id: string, name: string, full_name: string, default_branch: string}>
 */
function loadOriginRepositories(OriginApp $source): array
{
    $token = generateOriginInstallationToken($source);
    $repositories = [];
    $pageToken = '';

    for ($page = 0; $page < 50; $page++) {
        $response = originHttp($source, $token)->get('/installation/repos', array_filter([
            'pageSize' => 100,
            'pageToken' => $pageToken,
        ]));

        if (! $response->successful()) {
            throw new RuntimeException('Failed to load Origin repositories: '.originResponseError($response));
        }

        foreach (data_get($response->json(), 'repositories', []) as $repository) {
            $repositories[] = [
                'id' => (string) data_get($repository, 'id'),
                'name' => (string) data_get($repository, 'name'),
                'full_name' => (string) (data_get($repository, 'fullName') ?: data_get($repository, 'owner.slug').'/'.data_get($repository, 'name')),
                'default_branch' => (string) (data_get($repository, 'defaultBranch') ?: 'main'),
            ];
        }

        $pageToken = (string) data_get($response->json(), 'nextPageToken', '');
        if ($pageToken === '') {
            break;
        }
    }

    return $repositories;
}

/**
 * @return array<int, array{name: string}>
 */
function loadOriginBranches(OriginApp $source, string $repository): array
{
    $token = generateOriginInstallationToken($source);
    $branches = [];
    $pageToken = '';

    for ($page = 0; $page < 50; $page++) {
        $response = originHttp($source, $token)->get("/repos/{$repository}/branches", array_filter([
            'page_size' => 100,
            'page_token' => $pageToken,
        ]));

        if (! $response->successful()) {
            throw new RuntimeException('Failed to load Origin branches: '.originResponseError($response));
        }

        foreach (data_get($response->json(), 'branches', []) as $branch) {
            $branches[] = ['name' => (string) data_get($branch, 'name')];
        }

        $pageToken = (string) data_get($response->json(), 'nextPageToken', '');
        if ($pageToken === '') {
            break;
        }
    }

    return $branches;
}

/**
 * The head branch of a pull request. Origin exposes no refs/pull/{n}/head, so previews fetch this branch.
 */
function originPullRequestHeadRef(OriginApp $source, string $repository, int $pullRequestId): string
{
    $headRef = data_get(originApi($source, "/repos/{$repository}/pulls/{$pullRequestId}"), 'head.ref');

    if (! is_string($headRef) || $headRef === '') {
        throw new RuntimeException("Origin pull request #{$pullRequestId} has no head branch.");
    }

    return $headRef;
}

/**
 * Active Origin signing keys (raw 32-byte Ed25519 public keys) keyed by kid. Cached for ten minutes.
 *
 * @return array<string, string>
 */
function originSigningKeys(string $apiUrl, bool $refresh = false): array
{
    $cacheKey = 'origin-jwks:'.hash('sha256', rtrim($apiUrl, '/'));
    // Refresh at most once a minute, so forged signatures cannot make Coolify fetch the keys on every request.
    if ($refresh && Cache::add("{$cacheKey}:refreshed", true, 60)) {
        Cache::forget($cacheKey);
    }

    return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($apiUrl) {
        $response = Http::GitSource($apiUrl)->acceptJson()->timeout(10)->get(rtrim($apiUrl, '/').'/keys');
        if (! $response->successful()) {
            throw new RuntimeException('Failed to load Origin signing keys.');
        }

        $keys = [];
        foreach (data_get($response->json(), 'keys', []) as $jwk) {
            if (data_get($jwk, 'kty') !== 'OKP' || data_get($jwk, 'crv') !== 'Ed25519' || ! is_string(data_get($jwk, 'x'))) {
                continue;
            }
            $publicKey = base64_decode(strtr($jwk['x'], '-_', '+/'), true);
            if ($publicKey !== false && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[(string) data_get($jwk, 'kid', count($keys))] = $publicKey;
            }
        }

        return $keys;
    });
}

/**
 * Verify the installation receipt that Origin sends to the install callback.
 *
 * @return array{installation_id: string, namespace_id: ?string, state: ?string}
 */
function verifyOriginInstallationReceipt(OriginApp $source, string $receipt): array
{
    try {
        $token = (new Parser(new JoseEncoder))->parse($receipt);
    } catch (Throwable) {
        throw new RuntimeException('The Origin installation receipt is malformed.');
    }

    if (! $token instanceof Plain
        || $token->headers()->get('alg') !== 'EdDSA'
        || $token->headers()->get('typ') !== 'origin-installation-receipt+jwt') {
        throw new RuntimeException('The Origin installation receipt has an invalid header.');
    }

    $verify = function (bool $refresh) use ($source, $token): bool {
        $publicKey = originSigningKeys($source->api_url, $refresh)[(string) $token->headers()->get('kid')] ?? null;

        return $publicKey !== null && (new Eddsa)->verify($token->signature()->hash(), $token->payload(), InMemory::plainText($publicKey));
    };

    if (! $verify(false) && ! $verify(true)) {
        throw new RuntimeException('The Origin installation receipt signature is invalid.');
    }

    $claims = $token->claims();
    if (! $token->hasBeenIssuedBy(rtrim($source->api_url, '/'))
        || ! $token->isPermittedFor((string) $source->app_id)
        || $token->isExpired(now())) {
        throw new RuntimeException('The Origin installation receipt is expired or not for this app.');
    }

    $installationId = $claims->get('sub');
    if (! is_string($installationId) || $installationId === '') {
        throw new RuntimeException('The Origin installation receipt has no installation ID.');
    }

    return [
        'installation_id' => $installationId,
        'namespace_id' => $claims->get('namespace_id'),
        'state' => $claims->get('state'),
    ];
}

/**
 * Verify an Origin webhook: Ed25519 over lowercaseHex(SHA-256("{id}.{timestamp}.{body}")), timestamp within five minutes.
 */
function verifyOriginWebhookSignature(string $apiUrl, ?string $webhookId, ?string $timestamp, string $body, ?string $signatureHeader): bool
{
    if (blank($webhookId) || blank($signatureHeader) || ! ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > 300) {
        return false;
    }

    $signatures = collect(preg_split('/\s+/', trim($signatureHeader)))
        ->filter(fn (string $value) => str_starts_with($value, 'v1ed,'))
        ->map(fn (string $value) => base64_decode(substr($value, 5), true))
        ->filter(fn ($value) => is_string($value) && strlen($value) === SODIUM_CRYPTO_SIGN_BYTES);

    if ($signatures->isEmpty()) {
        return false;
    }

    $digest = hash('sha256', "{$webhookId}.{$timestamp}.{$body}");
    $verify = fn (bool $refresh): bool => collect(originSigningKeys($apiUrl, $refresh))->contains(
        fn (string $publicKey) => $signatures->contains(fn (string $signature) => sodium_crypto_sign_verify_detached($signature, $digest, $publicKey))
    );

    return $verify(false) || $verify(true);
}
