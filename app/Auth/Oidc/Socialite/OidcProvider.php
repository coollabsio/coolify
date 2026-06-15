<?php

namespace App\Auth\Oidc\Socialite;

use App\Auth\Oidc\Exceptions\OidcException;
use App\Auth\Oidc\OidcConfig;
use App\Auth\Oidc\OidcDiscoveryDocument;
use App\Auth\Oidc\OidcDiscoveryService;
use App\Auth\Oidc\OidcTokenValidator;
use App\Auth\Oidc\OidcUser;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\ProviderInterface;

class OidcProvider extends AbstractProvider implements ProviderInterface
{
    private const int OIDC_FLOW_TTL_MINUTES = 10;

    /**
     * @var array<int, string>
     */
    protected $scopes = ['openid', 'email', 'profile'];

    protected $scopeSeparator = ' ';

    protected ?OidcConfig $oidcConfig = null;

    protected ?OidcDiscoveryDocument $discovery = null;

    public function __construct(
        Request $request,
        protected OidcDiscoveryService $discoveryService,
        protected OidcTokenValidator $tokenValidator,
        string $clientId,
        string $clientSecret,
        string $redirectUrl,
    ) {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl);
    }

    public function setConfig(OidcConfig $config): self
    {
        $this->oidcConfig = $config;
        $this->clientId = $config->clientId;
        $this->clientSecret = $config->clientSecret;
        $this->redirectUrl = $config->redirectUri;
        $this->scopes = $config->scopes;
        $this->discovery = null;

        return $this;
    }

    public function getConfig(): OidcConfig
    {
        if ($this->oidcConfig === null) {
            throw new OidcException('OIDC provider config is not set.');
        }

        return $this->oidcConfig;
    }

    protected function getAuthUrl($state): string
    {
        $config = $this->getConfig();
        $nonce = Str::random(40);
        $this->putOidcFlowValue($this->nonceSessionKey($state), $nonce);

        $extra = ['nonce' => $nonce];
        if ($config->usePkce) {
            $verifier = $this->generateCodeVerifier();
            $this->putOidcFlowValue($this->verifierSessionKey($state), $verifier);
            $extra['code_challenge'] = $this->codeChallenge($verifier);
            $extra['code_challenge_method'] = 'S256';
        }

        return $this->buildAuthUrlFromBase($this->resolveDiscovery()->authorizationEndpoint, $state)
            .'&'.http_build_query($extra, '', '&', $this->encodingType);
    }

    protected function getTokenUrl(): string
    {
        return $this->resolveDiscovery()->tokenEndpoint;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->resolveDiscovery()->userinfoEndpoint, [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
            RequestOptions::CONNECT_TIMEOUT => 5,
            RequestOptions::TIMEOUT => 10,
        ]);

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user)
    {
        return (new OidcUser)->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'nickname' => $user['preferred_username'] ?? null,
            'name' => $this->resolveName($user),
            'email' => $user['email'] ?? null,
            'avatar' => $user['picture'] ?? null,
        ]);
    }

    public function user()
    {
        if ($this->user) {
            return $this->user;
        }

        if ($this->hasInvalidState()) {
            throw new InvalidStateException;
        }

        $tokenResponse = $this->getAccessTokenResponse($this->getCode());
        $accessToken = Arr::get($tokenResponse, 'access_token');
        $idToken = Arr::get($tokenResponse, 'id_token');

        if (! is_string($accessToken) || $accessToken === '' || ! is_string($idToken) || $idToken === '') {
            throw new OidcException('OIDC token endpoint did not return required tokens.');
        }

        $discovery = $this->resolveDiscovery();
        $config = $this->getConfig();
        $expectedNonce = $this->pullOidcFlowValue($this->nonceSessionKey((string) $this->request->input('state')));
        if ($expectedNonce === null) {
            throw new OidcException('OIDC login session expired. Please try again.');
        }

        $claims = $this->tokenValidator->validate(
            idToken: $idToken,
            discovery: $discovery,
            jwks: $this->discoveryService->jwks($discovery->jwksUri),
            clientId: $config->clientId,
            expectedNonce: $expectedNonce,
            clockSkewSeconds: $config->clockSkewSeconds,
        );

        $userinfo = $this->getUserByToken($accessToken);
        $merged = array_merge($userinfo, $claims);

        /** @var OidcUser $user */
        $user = $this->mapUserToObject($merged);
        $user->setIdTokenClaims($claims)
            ->setToken($accessToken)
            ->setRefreshToken(Arr::get($tokenResponse, 'refresh_token'))
            ->setExpiresIn(Arr::get($tokenResponse, 'expires_in'));

        return $this->user = $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code)
    {
        $fields = $this->getTokenFields($code);
        if ($this->getConfig()->usePkce) {
            $verifier = $this->pullOidcFlowValue($this->verifierSessionKey((string) $this->request->input('state')));
            if ($verifier !== null) {
                $fields['code_verifier'] = $verifier;
            }
        }

        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::HEADERS => ['Accept' => 'application/json'],
            RequestOptions::FORM_PARAMS => $fields,
            RequestOptions::CONNECT_TIMEOUT => 5,
            RequestOptions::TIMEOUT => 10,
        ]);

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function resolveDiscovery(): OidcDiscoveryDocument
    {
        return $this->discovery ??= $this->discoveryService->discover($this->getConfig()->issuerUrl);
    }

    protected function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    protected function codeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function resolveName(array $user): ?string
    {
        if (is_string($user['name'] ?? null) && $user['name'] !== '') {
            return $user['name'];
        }

        $name = trim(((string) ($user['given_name'] ?? '')).' '.((string) ($user['family_name'] ?? '')));

        return $name === '' ? null : $name;
    }

    protected function putOidcFlowValue(string $key, string $value): void
    {
        $this->request->session()->put($key, [
            'value' => $value,
            'expires_at' => now()->addMinutes(self::OIDC_FLOW_TTL_MINUTES)->timestamp,
        ]);
    }

    protected function pullOidcFlowValue(string $key): ?string
    {
        $entry = $this->request->session()->pull($key);

        if (! is_array($entry)) {
            return null;
        }

        $value = $entry['value'] ?? null;
        $expiresAt = $entry['expires_at'] ?? null;

        if (! is_string($value) || $value === '' || ! is_int($expiresAt)) {
            return null;
        }

        if ($expiresAt < now()->timestamp) {
            return null;
        }

        return $value;
    }

    protected function nonceSessionKey(string $state): string
    {
        return "oidc.nonce.{$state}";
    }

    protected function verifierSessionKey(string $state): string
    {
        return "oidc.code_verifier.{$state}";
    }
}
