<?php

namespace App\Livewire\Source\Github;

use App\Jobs\GithubAppPermissionJob;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Support\Facades\Http;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Livewire\Component;

class Change extends Component
{
    public string $webhook_endpoint;

    public ?string $ipv4 = null;

    public ?string $ipv6 = null;

    public ?string $fqdn = null;

    public ?bool $default_permissions = true;

    public ?bool $preview_deployment_permissions = true;

    public ?bool $administration = false;

    public $parameters;

    public ?GithubApp $github_app = null;

    public string $name;

    public bool $is_system_wide;

    public $applications;

    protected $rules = [
        'github_app.name' => 'required|string',
        'github_app.organization' => 'nullable|string',
        'github_app.api_url' => 'required|string',
        'github_app.html_url' => 'required|string',
        'github_app.custom_user' => 'required|string',
        'github_app.custom_port' => 'required|int',
        'github_app.app_id' => 'required|int',
        'github_app.installation_id' => 'required|int',
        'github_app.client_id' => 'required|string',
        'github_app.client_secret' => 'required|string',
        'github_app.webhook_secret' => 'required|string',
        'github_app.is_system_wide' => 'required|bool',
        'github_app.contents' => 'nullable|string',
        'github_app.metadata' => 'nullable|string',
        'github_app.pull_requests' => 'nullable|string',
        'github_app.administration' => 'nullable|string',
    ];

    public function boot()
    {
        if ($this->github_app) {
            $this->github_app->makeVisible(['client_secret', 'webhook_secret']);
        }
    }

    public function checkPermissions()
    {
        GithubAppPermissionJob::dispatchSync($this->github_app);
        $this->github_app->refresh()->makeVisible('client_secret')->makeVisible('webhook_secret');
        $this->dispatch('success', 'Github App permissions updated.');
    }

    // public function check()
    // {

    // Need administration:read:write permission
    // https://docs.github.com/en/rest/actions/self-hosted-runners?apiVersion=2022-11-28#list-self-hosted-runners-for-a-repository

    //     $github_access_token = generate_github_installation_token($this->github_app);
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

    //     ray($runners_by_repository);
    // }

    public function mount()
    {
        try {
            $github_app_uuid = request()->github_app_uuid;
            $this->github_app = GithubApp::ownedByCurrentTeam()->whereUuid($github_app_uuid)->firstOrFail();
            $this->github_app->makeVisible(['client_secret', 'webhook_secret']);

            $this->applications = $this->github_app->applications;
            $settings = instanceSettings();

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
                    $environment_name = data_get($parameters, 'environment_name');
                    $project_uuid = data_get($parameters, 'project_uuid');
                    $type = data_get($parameters, 'type');
                    $destination = data_get($parameters, 'destination');
                    session()->forget('from');

                    return redirect()->route($back, [
                        'environment_name' => $environment_name,
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
                $this->webhook_endpoint = $this->ipv4;
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
            $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');
            $this->validate([
                'github_app.name' => 'required|string',
                'github_app.organization' => 'nullable|string',
                'github_app.api_url' => 'required|string',
                'github_app.html_url' => 'required|string',
                'github_app.custom_user' => 'required|string',
                'github_app.custom_port' => 'required|int',
                'github_app.app_id' => 'required|int',
                'github_app.installation_id' => 'required|int',
                'github_app.client_id' => 'required|string',
                'github_app.client_secret' => 'required|string',
                'github_app.webhook_secret' => 'required|string',
                'github_app.is_system_wide' => 'required|bool',
            ]);
            $this->github_app->save();
            $this->dispatch('success', 'Github App updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        try {
            $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');
            $this->github_app->save();
            $this->dispatch('success', 'Github App updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete()
    {
        try {
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
