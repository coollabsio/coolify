<?php

namespace App\Jobs;

use App\Models\GithubApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GithubAppPermissionJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 4;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public GithubApp $github_app) {}

    public function handle()
    {
        Log::debug('Starting GithubAppPermissionJob', [
            'app_id' => $this->github_app->app_id,
            'installation_id' => $this->github_app->installation_id,
            'api_url' => $this->github_app->api_url,
        ]);

        try {
            Log::debug('Generating GitHub JWT token');
            $github_access_token = generateGithubJwt($this->github_app);

            Log::debug('Fetching app permissions from GitHub API');
            $response = Http::withHeaders([
                'Authorization' => "Bearer $github_access_token",
                'Accept' => 'application/vnd.github+json',
            ])->get("{$this->github_app->api_url}/app");

            if (! $response->successful()) {
                Log::error('GitHub API request failed', [
                    'status_code' => $response->status(),
                    'error' => $response->body(),
                    'app_id' => $this->github_app->app_id,
                ]);
                throw new \RuntimeException('Failed to fetch GitHub app permissions: '.$response->body());
            }

            $response = $response->json();
            $permissions = data_get($response, 'permissions');

            Log::debug('Retrieved GitHub permissions', [
                'app_id' => $this->github_app->app_id,
                'permissions' => $permissions,
            ]);

            $this->github_app->contents = data_get($permissions, 'contents');
            $this->github_app->metadata = data_get($permissions, 'metadata');
            $this->github_app->pull_requests = data_get($permissions, 'pull_requests');
            $this->github_app->administration = data_get($permissions, 'administration');

            Log::debug('Saving updated permissions to database', [
                'app_id' => $this->github_app->app_id,
                'contents' => $this->github_app->contents,
                'metadata' => $this->github_app->metadata,
                'pull_requests' => $this->github_app->pull_requests,
                'administration' => $this->github_app->administration,
            ]);

            $this->github_app->save();
            $this->github_app->makeVisible('client_secret')->makeVisible('webhook_secret');

            Log::debug('Successfully completed GithubAppPermissionJob', [
                'app_id' => $this->github_app->app_id,
            ]);

        } catch (\Throwable $e) {
            Log::error('GithubAppPermissionJob failed', [
                'app_id' => $this->github_app->app_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            send_internal_notification('GithubAppPermissionJob failed with: '.$e->getMessage());
            throw $e;
        }
    }
}
