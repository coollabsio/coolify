<?php

namespace App\Livewire\Source\Gitea;

use App\Models\GiteaApp;
use App\Models\PrivateKey;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Change extends Component
{
    use AuthorizesRequests;

    public string $webhook_endpoint = '';

    public string $custom_webhook_endpoint = '';

    public bool $use_custom_webhook_endpoint = false;

    public ?string $ipv4 = null;

    public ?string $ipv6 = null;

    public ?string $fqdn = null;

    public $parameters;

    public ?GiteaApp $gitea_app = null;

    public string $name;

    public string $apiUrl;

    public string $htmlUrl;

    public string $customUser;

    public int $customPort;

    public ?string $clientId = null;

    public ?string $clientSecretInput = null;

    public ?string $webhookSecret = null;

    public ?string $organization = null;

    public bool $isSystemWide;

    public ?int $privateKeyId = null;

    public $applications;

    public $privateKeys;

    public bool $isConnected = false;

    public ?string $redirectUri = null;

    public ?string $oauthState = null;

    private bool $shouldDeriveApiUrlAfterHtmlUrlUpdate = false;

    protected function rules(): array
    {
        return [
            'name' => 'required|string',
            'apiUrl' => ['required', 'string', 'url', SafeExternalUrl::forGitSource()],
            'htmlUrl' => ['required', 'string', 'url', SafeExternalUrl::forGitSource()],
            'customUser' => 'required|string',
            'customPort' => 'required|int',
            'clientId' => 'nullable|string',
            'clientSecretInput' => 'nullable|string',
            'webhookSecret' => 'nullable|string',
            'organization' => 'nullable|string',
            'isSystemWide' => 'required|bool',
            'privateKeyId' => ['nullable', 'integer', Rule::exists('private_keys', 'id')->where('team_id', $this->gitea_app->team_id)],
            'webhook_endpoint' => ['required', 'string', 'url'],
            'custom_webhook_endpoint' => ['nullable', 'string', 'url'],
            'use_custom_webhook_endpoint' => ['required', 'bool'],
        ];
    }

    public function updatingHtmlUrl(): void
    {
        $this->shouldDeriveApiUrlAfterHtmlUrlUpdate = blank($this->apiUrl)
            || $this->apiUrl === rtrim($this->htmlUrl, '/').'/api/v1';
    }

    public function updatedHtmlUrl(): void
    {
        if ($this->shouldDeriveApiUrlAfterHtmlUrlUpdate) {
            $this->apiUrl = rtrim($this->htmlUrl, '/').'/api/v1';
        }
    }

    public function updatedWebhookEndpoint(): void
    {
        $this->persistRedirectUriFromEndpoint();
    }

    public function updatedUseCustomWebhookEndpoint(): void
    {
        $this->persistRedirectUriFromEndpoint();
    }

    public function updatedCustomWebhookEndpoint(): void
    {
        $this->persistRedirectUriFromEndpoint();
    }

    private function persistRedirectUriFromEndpoint(): void
    {
        $this->refreshRedirectUri();

        if (! $this->gitea_app || blank($this->redirectUri)) {
            return;
        }

        try {
            $this->authorize('update', $this->gitea_app);
            if ($this->gitea_app->redirect_uri !== $this->redirectUri) {
                $this->gitea_app->redirect_uri = $this->redirectUri;
                $this->gitea_app->save();
            }
        } catch (\Throwable) {
            // Keep the live redirect URI even if the user cannot persist yet.
        }
    }

    public function mount()
    {
        try {
            $gitea_app_uuid = request()->gitea_app_uuid;
            $this->gitea_app = GiteaApp::where(function ($query) {
                $query->where('team_id', currentTeam()->id)->orWhere('is_system_wide', true);
            })->whereUuid($gitea_app_uuid)->firstOrFail();

            $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
            $this->applications = $this->gitea_app->applications()->whereRelation('environment.project.team', 'id', currentTeam()->id)->get();

            $settings = instanceSettings();

            $this->syncData(false);

            $this->isConnected = $this->gitea_app->isConnected();
            $this->fqdn = $settings->fqdn;

            if ($settings->public_ipv4) {
                $this->ipv4 = 'http://'.$settings->public_ipv4.':'.config('app.port');
            }
            if ($settings->public_ipv6) {
                $this->ipv6 = 'http://'.$settings->public_ipv6.':'.config('app.port');
            }

            $this->parameters = get_route_parameters();

            if (isCloud() && ! isDev()) {
                $this->webhook_endpoint = config('app.url');
            } else {
                $this->webhook_endpoint = $this->fqdn ?? $this->ipv4 ?? $this->ipv6 ?? config('app.url') ?? '';
            }

            // Prefer a previously saved redirect base when it matches one of the selectable endpoints
            // or when it differs (restore custom mode for self-hosted / tunnel setups).
            $savedRedirect = $this->gitea_app->redirect_uri;
            if (filled($savedRedirect)) {
                $savedBase = rtrim(str($savedRedirect)->before('/webhooks/source/gitea/redirect')->toString(), '/');
                $known = collect([$this->fqdn, $this->ipv4, $this->ipv6, config('app.url')])
                    ->filter()
                    ->map(fn ($url) => rtrim((string) $url, '/'));

                if ($known->contains($savedBase)) {
                    $this->webhook_endpoint = $savedBase;
                    $this->use_custom_webhook_endpoint = false;
                } elseif (! (isCloud() && ! isDev()) && filled($savedBase)) {
                    $this->use_custom_webhook_endpoint = true;
                    $this->custom_webhook_endpoint = $savedBase;
                }
            }

            $this->refreshRedirectUri();

            $this->oauthState = $this->createOAuthState();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function refreshRedirectUri(): void
    {
        $base = $this->resolvePublicBaseUrl();
        $this->redirectUri = $base === ''
            ? ''
            : $base.'/webhooks/source/gitea/redirect';
    }

    public function resolvePublicBaseUrl(): string
    {
        if ($this->use_custom_webhook_endpoint && filled($this->custom_webhook_endpoint)) {
            return rtrim($this->custom_webhook_endpoint, '/');
        }

        return rtrim($this->webhook_endpoint ?: (config('app.url') ?? ''), '/');
    }

    public static function oauthStateCacheKey(string $state): string
    {
        return 'gitea-app-oauth-state:'.hash('sha256', $state);
    }

    private function createOAuthState(): string
    {
        $state = Str::random(64);

        Cache::put(self::oauthStateCacheKey($state), [
            'gitea_app_id' => $this->gitea_app->id,
            'team_id' => currentTeam()->id,
        ], now()->addMinutes(60));

        return $state;
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->gitea_app->name = $this->name;
            $this->gitea_app->api_url = $this->apiUrl;
            $this->gitea_app->html_url = rtrim($this->htmlUrl, '/');
            $this->gitea_app->custom_user = $this->customUser;
            $this->gitea_app->custom_port = $this->customPort;
            $this->gitea_app->client_id = $this->clientId;
            if (! empty($this->clientSecretInput)) {
                $this->gitea_app->client_secret = $this->clientSecretInput;
            }
            if (! empty($this->webhookSecret)) {
                $this->gitea_app->webhook_secret = $this->webhookSecret;
            }
            $this->gitea_app->organization = $this->organization;
            $this->gitea_app->is_system_wide = $this->isSystemWide;
            $this->gitea_app->private_key_id = $this->privateKeyId;
            $this->refreshRedirectUri();
            $this->gitea_app->redirect_uri = $this->redirectUri;
        } else {
            $this->name = $this->gitea_app->name;
            $this->apiUrl = $this->gitea_app->api_url;
            $this->htmlUrl = $this->gitea_app->html_url;
            $this->customUser = $this->gitea_app->custom_user;
            $this->customPort = $this->gitea_app->custom_port;
            $this->clientId = $this->gitea_app->client_id;
            if (Gate::allows('update', $this->gitea_app)) {
                $this->clientSecretInput = $this->gitea_app->client_secret;
                $this->webhookSecret = $this->gitea_app->webhook_secret;
            }
            $this->organization = $this->gitea_app->organization;
            $this->isSystemWide = $this->gitea_app->is_system_wide;
            $this->privateKeyId = $this->gitea_app->private_key_id;
        }
    }

    private function authorizeSystemWideChange(): void
    {
        if ($this->isSystemWide && ! $this->gitea_app->is_system_wide) {
            $this->authorize('makeSystemWide', GiteaApp::class);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->gitea_app);
            $this->authorizeSystemWideChange();

            $this->validate();

            $this->syncData(true);
            $this->gitea_app->save();
            $this->dispatch('success', 'Gitea App updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->gitea_app);
            $this->authorizeSystemWideChange();

            $this->validateOnly('isSystemWide');

            $this->gitea_app->is_system_wide = $this->isSystemWide;
            $this->gitea_app->save();
            $this->dispatch('success', 'Gitea App updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function testConnection()
    {
        try {
            $this->authorize('view', $this->gitea_app);

            if (! $this->gitea_app->isConnected()) {
                $this->dispatch('error', 'Gitea App is not connected. Please complete the OAuth flow first.');

                return;
            }

            refreshGiteaToken($this->gitea_app);

            $apiUrl = $this->gitea_app->apiUrlBase();
            $response = Http::Gitea($apiUrl, $this->gitea_app->access_token)
                ->timeout(10)
                ->get('/user');

            if ($response->successful()) {
                $login = data_get($response->json(), 'login', 'unknown');
                $this->dispatch('success', "Connection successful! Authenticated as: {$login}");
            } else {
                $error = data_get($response->json(), 'message', 'Unknown error');
                $this->dispatch('error', "Connection failed: {$error}");
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->gitea_app);

            if ($this->gitea_app->applications->isNotEmpty()) {
                $this->dispatch('error', 'This source is being used by an application. Please delete all applications first.');

                return;
            }
            $this->gitea_app->delete();
            // Clear so post-delete Livewire re-render / modal $refresh does not re-run
            // @can and canGate checks against a deleted model (null team_id TypeError).
            $this->gitea_app = null;

            return redirectRoute($this, 'source.all');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getOAuthUrl(): string
    {
        $this->refreshRedirectUri();
        $baseUrl = rtrim($this->htmlUrl, '/');

        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'read:user read:repository read:organization',
            'state' => $this->oauthState ??= $this->createOAuthState(),
        ]);

        return "{$baseUrl}/login/oauth/authorize?{$query}";
    }
}
