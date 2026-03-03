<?php

namespace App\Livewire\Source\Github\Tabs;

use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;

    public GithubApp $github_app;

    public Collection $applications;

    public Collection $privateKeys;

    public string $name;

    public ?string $organization = null;

    public string $apiUrl;

    public string $htmlUrl;

    public string $customUser;

    public int $customPort;

    public ?int $appId = null;

    public ?int $installationId = null;

    public ?string $clientId = null;

    public ?string $clientSecret = null;

    public ?string $webhookSecret = null;

    public bool $isSystemWide = false;

    public ?int $privateKeyId = null;

    protected $rules = [
        'name' => 'required|string',
        'organization' => 'nullable|string',
        'apiUrl' => 'required|string',
        'htmlUrl' => 'required|string',
        'customUser' => 'required|string',
        'customPort' => 'required|int',
        'appId' => 'nullable|int',
        'installationId' => 'nullable|int',
        'clientId' => 'nullable|string',
        'clientSecret' => 'nullable|string',
        'webhookSecret' => 'nullable|string',
        'isSystemWide' => 'required|bool',
        'privateKeyId' => 'nullable|int',
    ];

    public function mount(string $githubAppUuid): void
    {
        $this->github_app = GithubApp::ownedByCurrentTeam()->whereUuid($githubAppUuid)->firstOrFail();
        $this->github_app->makeVisible(['client_secret', 'webhook_secret']);
        $this->applications = $this->github_app->applications;
        $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();

        $this->syncData();
        $this->name = str($this->github_app->name)->kebab();
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->github_app->name = $this->name;
            $this->github_app->organization = $this->organization;
            $this->github_app->api_url = $this->apiUrl;
            $this->github_app->html_url = $this->htmlUrl;
            $this->github_app->custom_user = $this->customUser;
            $this->github_app->custom_port = $this->customPort;
            $this->github_app->app_id = $this->appId;
            $this->github_app->installation_id = $this->installationId;
            $this->github_app->client_id = $this->clientId;
            $this->github_app->client_secret = $this->clientSecret;
            $this->github_app->webhook_secret = $this->webhookSecret;
            $this->github_app->is_system_wide = $this->isSystemWide;
            $this->github_app->private_key_id = $this->privateKeyId;

            return;
        }

        $this->name = $this->github_app->name;
        $this->organization = $this->github_app->organization;
        $this->apiUrl = $this->github_app->api_url;
        $this->htmlUrl = $this->github_app->html_url;
        $this->customUser = $this->github_app->custom_user;
        $this->customPort = $this->github_app->custom_port;
        $this->appId = $this->github_app->app_id;
        $this->installationId = $this->github_app->installation_id;
        $this->clientId = $this->github_app->client_id;
        $this->clientSecret = $this->github_app->client_secret;
        $this->webhookSecret = $this->github_app->webhook_secret;
        $this->isSystemWide = $this->github_app->is_system_wide;
        $this->privateKeyId = $this->github_app->private_key_id;
    }

    public function getGithubAppNameUpdatePath(): string
    {
        if (str($this->github_app->organization)->isNotEmpty()) {
            return "{$this->github_app->html_url}/organizations/{$this->github_app->organization}/settings/apps/{$this->github_app->name}";
        }

        return "{$this->github_app->html_url}/settings/apps/{$this->github_app->name}";
    }

    private function generateGithubJwt(string $privateKey, int $appId): string
    {
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($privateKey),
            InMemory::plainText($privateKey)
        );

        $now = time();

        return $configuration->builder()
            ->issuedBy((string) $appId)
            ->permittedFor('https://api.github.com')
            ->identifiedBy((string) $now)
            ->issuedAt(new \DateTimeImmutable("@{$now}"))
            ->expiresAt(new \DateTimeImmutable('@'.($now + 600)))
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    public function updateGithubAppName(): void
    {
        try {
            $this->authorize('update', $this->github_app);

            $privateKey = PrivateKey::ownedByCurrentTeam()->find($this->github_app->private_key_id);

            if (! $privateKey) {
                $this->dispatch('error', 'No private key found for this GitHub App.');

                return;
            }

            if (! $this->github_app->app_id) {
                $this->dispatch('error', 'No App ID found for this GitHub App.');

                return;
            }

            $jwt = $this->generateGithubJwt($privateKey->private_key, $this->github_app->app_id);
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'Authorization' => "Bearer {$jwt}",
            ])->get("{$this->github_app->api_url}/app");

            if (! $response->successful()) {
                $errorMessage = $response->json()['message'] ?? 'Unknown error';
                $this->dispatch('error', "Failed to fetch GitHub App information: {$errorMessage}");

                return;
            }

            $appData = $response->json();
            $appSlug = $appData['slug'] ?? null;

            if (! $appSlug) {
                $this->dispatch('info', 'Could not find App Name (slug) in GitHub response.');

                return;
            }

            $this->github_app->name = $appSlug;
            $this->name = str($appSlug)->kebab();
            $privateKey->name = "github-app-{$appSlug}";
            $privateKey->save();
            $this->github_app->save();
            $this->dispatch('success', 'GitHub App name and SSH key name synchronized successfully.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function submit(): void
    {
        try {
            $this->authorize('update', $this->github_app);

            $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');
            $this->validate();
            $this->syncData(true);
            $this->github_app->save();
            $this->dispatch('success', 'Github App updated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function instantSave(): void
    {
        try {
            $this->authorize('update', $this->github_app);

            $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');
            $this->syncData(true);
            $this->github_app->save();
            $this->dispatch('success', 'Github App updated.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->github_app);

            if ($this->github_app->applications->isNotEmpty()) {
                $this->dispatch('error', 'This source is being used by an application. Please delete all applications first.');
                $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');

                return;
            }

            $this->github_app->delete();

            return redirect()->route('source.all');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.source.github.tabs.general');
    }
}
