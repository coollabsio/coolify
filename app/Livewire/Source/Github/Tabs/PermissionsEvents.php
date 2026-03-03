<?php

namespace App\Livewire\Source\Github\Tabs;

use App\Jobs\GithubAppPermissionJob;
use App\Models\GithubApp;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class PermissionsEvents extends Component
{
    use AuthorizesRequests;

    public GithubApp $github_app;

    public ?int $appId = null;

    public ?int $privateKeyId = null;

    public ?string $contents = null;

    public ?string $metadata = null;

    public ?string $pullRequests = null;

    public ?string $organizationSelfHostedRunners = null;

    public ?array $webhookEvents = null;

    public function mount(string $githubAppUuid): void
    {
        $this->github_app = GithubApp::ownedByCurrentTeam()->whereUuid($githubAppUuid)->firstOrFail();
        $this->github_app->makeVisible(['client_secret', 'webhook_secret']);
        $this->syncData();
    }

    private function syncData(): void
    {
        $this->appId = $this->github_app->app_id;
        $this->privateKeyId = $this->github_app->private_key_id;
        $this->contents = $this->github_app->contents;
        $this->metadata = $this->github_app->metadata;
        $this->pullRequests = $this->github_app->pull_requests;
        $this->organizationSelfHostedRunners = $this->github_app->organization_self_hosted_runners;
        $this->webhookEvents = $this->github_app->webhook_events;
    }

    public function checkPermissions(): void
    {
        try {
            $this->authorize('view', $this->github_app);

            $missingFields = [];

            if (! $this->github_app->app_id) {
                $missingFields[] = 'App ID';
            }

            if (! $this->github_app->private_key_id) {
                $missingFields[] = 'Private Key';
            }

            if (! empty($missingFields)) {
                $fieldsList = implode(', ', $missingFields);
                $this->dispatch('error', "Cannot fetch permissions. Please set the following required fields first: {$fieldsList}");

                return;
            }

            if (! $this->github_app->privateKey) {
                $this->dispatch('error', 'Private Key not found. Please select a valid private key.');

                return;
            }

            $previousEvents = $this->github_app->webhook_events ?? [];
            GithubAppPermissionJob::dispatchSync($this->github_app);
            $this->github_app->refresh()->makeVisible('client_secret')->makeVisible('webhook_secret');
            $this->syncData();

            $addedEvents = array_diff($this->github_app->webhook_events ?? [], $previousEvents);
            if (! empty($addedEvents)) {
                $this->dispatch('success', 'Permissions updated. Auto-enabled missing events: '.implode(', ', $addedEvents));

                return;
            }

            $this->dispatch('success', 'Github App permissions updated.');
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'DECODER routines::unsupported') || str_contains($errorMessage, 'parse your key')) {
                $this->dispatch('error', 'The selected private key format is not supported for GitHub Apps. <br><br>Please use an RSA private key in PEM format (BEGIN RSA PRIVATE KEY). <br><br>OpenSSH format keys (BEGIN OPENSSH PRIVATE KEY) are not supported.');

                return;
            }

            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.source.github.tabs.permissions-events');
    }
}
