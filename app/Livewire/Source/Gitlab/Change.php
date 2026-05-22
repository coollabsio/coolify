<?php

namespace App\Livewire\Source\Gitlab;

use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class Change extends Component
{
    use AuthorizesRequests;

    public string $webhook_endpoint = '';

    public ?string $ipv4 = null;

    public ?string $ipv6 = null;

    public ?string $fqdn = null;

    public $parameters;

    public ?GitlabApp $gitlab_app = null;

    public string $name;

    public string $apiUrl;

    public string $htmlUrl;

    public string $customUser;

    public int $customPort;

    public ?string $clientId = null;

    public ?string $clientSecretInput = null;

    public ?string $webhookToken = null;

    public ?string $groupName = null;

    public bool $isSystemWide;

    public ?int $privateKeyId = null;

    public $applications;

    public $privateKeys;

    public bool $isConnected = false;

    public ?string $redirectUri = null;

    protected function rules(): array
    {
        return [
            'name' => 'required|string',
            'apiUrl' => ['required', 'string', 'url', new SafeExternalUrl],
            'htmlUrl' => ['required', 'string', 'url', new SafeExternalUrl],
            'customUser' => 'required|string',
            'customPort' => 'required|int',
            'clientId' => 'nullable|string',
            'clientSecretInput' => 'nullable|string',
            'webhookToken' => 'nullable|string',
            'groupName' => 'nullable|string',
            'isSystemWide' => 'required|bool',
            'privateKeyId' => 'nullable|int',
        ];
    }

    public function mount()
    {
        try {
            $gitlab_app_uuid = request()->gitlab_app_uuid;
            $this->gitlab_app = GitlabApp::where(function ($query) {
                $query->where('team_id', currentTeam()->id)->orWhere('is_system_wide', true);
            })->whereUuid($gitlab_app_uuid)->firstOrFail();

            $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
            $this->applications = $this->gitlab_app->applications;

            $settings = instanceSettings();

            $this->syncData(false);

            $this->isConnected = $this->gitlab_app->isConnected();
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
                $this->webhook_endpoint = $this->fqdn ?? $this->ipv4 ?? '';
            }

            $this->redirectUri = $this->webhook_endpoint.'/webhooks/source/gitlab/redirect';
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->gitlab_app->name = $this->name;
            $this->gitlab_app->api_url = $this->apiUrl;
            $this->gitlab_app->html_url = rtrim($this->htmlUrl, '/');
            $this->gitlab_app->custom_user = $this->customUser;
            $this->gitlab_app->custom_port = $this->customPort;
            $this->gitlab_app->client_id = $this->clientId;
            if (! empty($this->clientSecretInput)) {
                $this->gitlab_app->client_secret = $this->clientSecretInput;
            }
            $this->gitlab_app->webhook_token = $this->webhookToken;
            $this->gitlab_app->group_name = $this->groupName;
            $this->gitlab_app->is_system_wide = $this->isSystemWide;
            $this->gitlab_app->private_key_id = $this->privateKeyId;
            $this->gitlab_app->redirect_uri = $this->redirectUri;
        } else {
            $this->name = $this->gitlab_app->name;
            $this->apiUrl = $this->gitlab_app->api_url;
            $this->htmlUrl = $this->gitlab_app->html_url;
            $this->customUser = $this->gitlab_app->custom_user;
            $this->customPort = $this->gitlab_app->custom_port;
            $this->clientId = $this->gitlab_app->client_id;
            $this->clientSecretInput = null;
            $this->webhookToken = $this->gitlab_app->webhook_token;
            $this->groupName = $this->gitlab_app->group_name;
            $this->isSystemWide = $this->gitlab_app->is_system_wide;
            $this->privateKeyId = $this->gitlab_app->private_key_id;
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->gitlab_app);

            $this->validate();

            $this->syncData(true);
            $this->gitlab_app->save();
            $this->dispatch('success', 'GitLab App updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->gitlab_app);

            $this->gitlab_app->makeVisible(['client_secret', 'webhook_token', 'access_token', 'refresh_token']);
            $this->syncData(true);
            $this->gitlab_app->save();
            $this->dispatch('success', 'GitLab App updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function testConnection()
    {
        try {
            $this->authorize('view', $this->gitlab_app);

            if (! $this->gitlab_app->isConnected()) {
                $this->dispatch('error', 'GitLab App is not connected. Please complete the OAuth flow first.');

                return;
            }

            refreshGitlabToken($this->gitlab_app);

            $apiUrl = $this->gitlab_app->apiUrlBase();
            $response = Http::GitLab($apiUrl, $this->gitlab_app->access_token)
                ->timeout(10)
                ->get('/user');

            if ($response->successful()) {
                $username = data_get($response->json(), 'username', 'unknown');
                $this->dispatch('success', "Connection successful! Authenticated as: {$username}");
            } else {
                $error = data_get($response->json(), 'message', 'Unknown error');
                $this->dispatch('error', "Connection failed: {$error}");
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function disconnect()
    {
        try {
            $this->authorize('update', $this->gitlab_app);

            $this->gitlab_app->update([
                'access_token' => null,
                'refresh_token' => null,
                'expires_at' => null,
            ]);

            $this->isConnected = false;
            $this->dispatch('success', 'GitLab App disconnected.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->gitlab_app);

            if ($this->gitlab_app->applications->isNotEmpty()) {
                $this->dispatch('error', 'This source is being used by an application. Please delete all applications first.');

                return;
            }
            $this->gitlab_app->delete();

            return redirect()->route('source.all');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getOAuthUrl(): string
    {
        $baseUrl = rtrim($this->htmlUrl, '/');

        $query = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'api read_user read_repository',
            'state' => $this->gitlab_app->uuid,
        ]);

        return "{$baseUrl}/oauth/authorize?{$query}";
    }
}
