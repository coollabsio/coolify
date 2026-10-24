<?php

namespace App\Livewire\Source\Gitea;

use App\Models\GiteaApp;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public string $name;

    public string $html_url = 'https://gitea.com';

    public string $api_url = 'https://gitea.com/api/v1';

    public string $custom_user = 'git';

    public int $custom_port = 22;

    public bool $is_system_wide = false;

    public ?string $organization = null;

    private bool $shouldDeriveApiUrlAfterHtmlUrlUpdate = false;

    public function mount()
    {
        $this->name = substr(generate_random_name(), 0, 30);
    }

    public function updatingHtmlUrl(): void
    {
        $this->shouldDeriveApiUrlAfterHtmlUrlUpdate = blank($this->api_url)
            || $this->api_url === $this->giteaApiUrlFromHtmlUrl($this->html_url);
    }

    public function updatedHtmlUrl(): void
    {
        if ($this->shouldDeriveApiUrlAfterHtmlUrlUpdate) {
            $this->api_url = $this->giteaApiUrlFromHtmlUrl($this->html_url);
        }
    }

    public function createGiteaApp()
    {
        try {
            $this->authorize('createAnyResource');

            $this->html_url = rtrim($this->html_url, '/');
            $this->api_url = filled($this->api_url)
                ? rtrim($this->api_url, '/')
                : $this->giteaApiUrlFromHtmlUrl($this->html_url);

            $this->validate([
                'name' => 'required|string',
                'html_url' => ['required', 'string', 'url', SafeExternalUrl::forGitSource()],
                'api_url' => ['required', 'string', 'url', SafeExternalUrl::forGitSource()],
                'custom_user' => 'required|string',
                'custom_port' => 'required|int',
                'is_system_wide' => 'required|bool',
                'organization' => 'nullable|string',
            ]);

            if ($this->is_system_wide) {
                $this->authorize('makeSystemWide', GiteaApp::class);
            }

            $gitea_app = GiteaApp::create([
                'name' => $this->name,
                'api_url' => $this->api_url,
                'html_url' => $this->html_url,
                'custom_user' => $this->custom_user,
                'custom_port' => $this->custom_port,
                'is_system_wide' => $this->is_system_wide,
                'organization' => $this->organization,
                'webhook_secret' => Str::random(32),
                'team_id' => currentTeam()->id,
            ]);

            if (session('from')) {
                session(['from' => session('from') + ['source_id' => $gitea_app->id]]);
            }

            return redirectRoute($this, 'source.gitea.show', ['gitea_app_uuid' => $gitea_app->uuid]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function giteaApiUrlFromHtmlUrl(string $htmlUrl): string
    {
        return rtrim($htmlUrl, '/').'/api/v1';
    }
}
