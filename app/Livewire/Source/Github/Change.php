<?php

namespace App\Livewire\Source\Github;

use App\Jobs\GithubAppPermissionJob;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Livewire\Component;

class Change extends Component
{
    use AuthorizesRequests;

    public string $webhook_endpoint = '';

    public ?string $ipv4 = null;

    public ?string $ipv6 = null;

    public ?string $fqdn = null;

    public ?bool $default_permissions = true;

    public ?bool $preview_deployment_permissions = true;

    public ?bool $administration = false;

    public $parameters;

    public ?GithubApp $github_app = null;

    // Explicit properties
    public string $name;

    public ?string $organization = null;

    public string $apiUrl;

    public string $htmlUrl;

    public string $customUser;

    public int $customPort;

    public int $appId;

    public int $installationId;

    public string $clientId;

    public string $clientSecret;

    public string $webhookSecret;

    public bool $isSystemWide;

    public int $privateKeyId;

    public ?string $contents = null;

    public ?string $metadata = null;

    public ?string $pullRequests = null;

    public $applications;

    public $privateKeys;

    protected $rules = [
        'name' => 'required|string',
        'organization' => 'nullable|string',
        'apiUrl' => 'required|string',
        'htmlUrl' => 'required|string',
        'customUser' => 'required|string',
        'customPort' => 'required|int',
        'appId' => 'required|int',
        'installationId' => 'required|int',
        'clientId' => 'required|string',
        'clientSecret' => 'required|string',
        'webhookSecret' => 'required|string',
        'isSystemWide' => 'required|bool',
        'contents' => 'nullable|string',
        'metadata' => 'nullable|string',
        'pullRequests' => 'nullable|string',
        'privateKeyId' => 'required|int',
    ];

    public function boot()
    {
        if ($this->github_app) {
            $this->github_app->makeVisible(['client_secret', 'webhook_secret']);
        }
    }

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
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
            $this->github_app->contents = $this->contents;
            $this->github_app->metadata = $this->metadata;
            $this->github_app->pull_requests = $this->pullRequests;
        } else {
            // Sync FROM model (on load/refresh)
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
            $this->contents = $this->github_app->contents;
            $this->metadata = $this->github_app->metadata;
            $this->pullRequests = $this->github_app->pull_requests;
        }
    }

    public function checkPermissions()
    {
        try {
            $this->authorize('view', $this->github_app);

            GithubAppPermissionJob::dispatchSync($this->github_app);
            $this->github_app->refresh()->makeVisible('client_secret')->makeVisible('webhook_secret');
            $this->dispatch('success', 'Github App permissions updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    // public function check()
    // {

    // Need administration:read:write permission
    // https://docs.github.com/en/rest/actions/self-hosted-runners?apiVersion=2022-11-28#list-self-hosted-runners-for-a-repository

    //     $github_access_token = generateGithubInstallationToken($this->github_app);
    //     $repositories = Http::withToken($github_access_token)->get("{$this->github_app->api_url}/installation/repositories?per_page=100");
    //     $runners_by_repository = collect([]);
    //     $repositories = $repositories->json()['repositories'];
    //     foreach ($repositories as $repository) {
    //         $runners_downloads = Http::withToken($github_access_token)->get("{$this->github_app->api_url}/repos/{$repository['full_name']}/actions/runners/downloads");
    //         $runners = Http::withToken($github_access_token)->get("{$this->github_app->api_url}/repos/{$repository['full_name']}/actions/runners");
    //         $token = Http::withHeaders([
    //             'Authorization' => "Bearer $github_access_token",
    //             'Accept' => 'application/vnd.github+json'
    //         ])->withBody(null)->post("{$this->github_app->api_url}/repos/{$repository['full_name']}/actions/runners/registration-token");
    //         $token = $token->json();
    //         $remove_token = Http::withHeaders([
    //             'Authorization' => "Bearer $github_access_token",
    //             'Accept' => 'application/vnd.github+json'
    //         ])->withBody(null)->post("{$this->github_app->api_url}/repos/{$repository['full_name']}/actions/runners/remove-token");
    //         $remove_token = $remove_token->json();
    //         $runners_by_repository->put($repository['full_name'], [
    //             'token' => $token,
    //             'remove_token' => $remove_token,
    //             'runners' => $runners->json(),
    //             'runners_downloads' => $runners_downloads->json()
    //         ]);
    //     }

    // }

    public function mount()
    {
        try {
            $github_app_uuid = request()->github_app_uuid;
            $this->github_app = GithubApp::ownedByCurrentTeam()->whereUuid($github_app_uuid)->firstOrFail();
            $this->github_app->makeVisible(['client_secret', 'webhook_secret']);
            $this->privateKeys = PrivateKey::ownedByCurrentTeam()->get();

            $this->applications = $this->github_app->applications;
            $settings = instanceSettings();

            // Sync data from model to properties
            $this->syncData(false);

            // Override name with kebab case for display
            $this->name = str($this->github_app->name)->kebab();
            $this->fqdn = $settings->fqdn;

            if ($settings->public_ipv4) {
                $this->ipv4 = 'http://'.$settings->public_ipv4.':'.config('app.port');
            }
            if ($settings->public_ipv6) {
                $this->ipv6 = 'http://'.$settings->public_ipv6.':'.config('app.port');
            }
            if ($this->github_app->installation_id && session('from')) {
                $source_id = data_get(session('from'), 'source_id');
                if (! $source_id || $this->github_app->id !== $source_id) {
                    session()->forget('from');
                } else {
                    $parameters = data_get(session('from'), 'parameters');
                    $back = data_get(session('from'), 'back');
                    $environment_uuid = data_get($parameters, 'environment_uuid');
                    $project_uuid = data_get($parameters, 'project_uuid');
                    $type = data_get($parameters, 'type');
                    $destination = data_get($parameters, 'destination');
                    session()->forget('from');

                    return redirect()->route($back, [
                        'environment_uuid' => $environment_uuid,
                        'project_uuid' => $project_uuid,
                        'type' => $type,
                        'destination' => $destination,
                    ]);
                }
            }
            $this->parameters = get_route_parameters();
            if (isCloud() && ! isDev()) {
                $this->webhook_endpoint = config('app.url');
            } else {
                $this->webhook_endpoint = $this->ipv4 ?? '';
                $this->is_system_wide = $this->github_app->is_system_wide;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getGithubAppNameUpdatePath()
    {
        if (str($this->github_app->organization)->isNotEmpty()) {
            return "{$this->github_app->html_url}/organizations/{$this->github_app->organization}/settings/apps/{$this->github_app->name}";
        }

        return "{$this->github_app->html_url}/settings/apps/{$this->github_app->name}";
    }

    private function generateGithubJwt($private_key, $app_id): string
    {
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($private_key),
            InMemory::plainText($private_key)
        );

        $now = time();

        return $configuration->builder()
            ->issuedBy((string) $app_id)
            ->permittedFor('https://api.github.com')
            ->identifiedBy((string) $now)
            ->issuedAt(new \DateTimeImmutable("@{$now}"))
            ->expiresAt(new \DateTimeImmutable('@'.($now + 600)))
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    public function updateGithubAppName()
    {
        try {
            $this->authorize('update', $this->github_app);

            $privateKey = PrivateKey::ownedByCurrentTeam()->find($this->github_app->private_key_id);

            if (! $privateKey) {
                $this->dispatch('error', 'No private key found for this GitHub App.');

                return;
            }

            $jwt = $this->generateGithubJwt($privateKey->private_key, $this->github_app->app_id);

            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'Authorization' => "Bearer {$jwt}",
            ])->get("{$this->github_app->api_url}/app");

            if ($response->successful()) {
                $app_data = $response->json();
                $app_slug = $app_data['slug'] ?? null;

                if ($app_slug) {
                    $this->github_app->name = $app_slug;
                    $this->name = str($app_slug)->kebab();
                    $privateKey->name = "github-app-{$app_slug}";
                    $privateKey->save();
                    $this->github_app->save();
                    $this->dispatch('success', 'GitHub App name and SSH key name synchronized successfully.');
                } else {
                    $this->dispatch('info', 'Could not find App Name (slug) in GitHub response.');
                }
            } else {
                $error_message = $response->json()['message'] ?? 'Unknown error';
                $this->dispatch('error', "Failed to fetch GitHub App information: {$error_message}");
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->github_app);

            $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');
            $this->validate();

            $this->syncData(true);
            $this->github_app->save();
            $this->dispatch('success', 'Github App updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function createGithubAppManually()
    {
        $this->authorize('update', $this->github_app);

        $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');
        $this->github_app->app_id = '1234567890';
        $this->github_app->installation_id = '1234567890';
        $this->github_app->save();
        $this->dispatch('success', 'Github App updated.');
    }

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->github_app);

            $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');

            $this->syncData(true);
            $this->github_app->save();
            $this->dispatch('success', 'Github App updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
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
            return handleError($e, $this);
        }
    }
}
