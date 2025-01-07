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
use Throwable;

class GithubAppPermissionJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 4;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public GithubApp $githubApp) {}

    public function handle()
    {
        try {
            $github_access_token = generate_github_jwt_token($this->githubApp);
            $response = Http::withHeaders([
                'Authorization' => "Bearer $github_access_token",
                'Accept' => 'application/vnd.github+json',
            ])->get("{$this->githubApp->api_url}/app");
            $response = $response->json();
            $permissions = data_get($response, 'permissions');
            $this->githubApp->contents = data_get($permissions, 'contents');
            $this->githubApp->metadata = data_get($permissions, 'metadata');
            $this->githubApp->pull_requests = data_get($permissions, 'pull_requests');
            $this->githubApp->administration = data_get($permissions, 'administration');
            $this->githubApp->save();
            $this->githubApp->makeVisible('client_secret')->makeVisible('webhook_secret');
        } catch (Throwable $e) {
            send_internal_notification('GithubAppPermissionJob failed with: '.$e->getMessage());
            throw $e;
        }
    }
}
