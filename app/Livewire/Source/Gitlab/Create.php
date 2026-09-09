<?php

namespace App\Livewire\Source\Gitlab;

use App\Models\GitlabApp;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public string $name;

    public string $html_url = 'https://gitlab.com';

    public string $api_url = 'https://gitlab.com/api/v4';

    public string $custom_user = 'git';

    public int $custom_port = 22;

    public bool $is_system_wide = false;

    public ?string $group_name = null;

    private bool $shouldDeriveApiUrlAfterHtmlUrlUpdate = false;

    public function mount()
    {
        $this->name = substr(generate_random_name(), 0, 30);
    }

    public function updatingHtmlUrl(): void
    {
        $this->shouldDeriveApiUrlAfterHtmlUrlUpdate = blank($this->api_url)
            || $this->api_url === $this->gitlabApiUrlFromHtmlUrl($this->html_url);
    }

    public function updatedHtmlUrl(): void
    {
        if ($this->shouldDeriveApiUrlAfterHtmlUrlUpdate) {
            $this->api_url = $this->gitlabApiUrlFromHtmlUrl($this->html_url);
        }
    }

    public function createGitLabApp()
    {
        try {
            $this->authorize('createAnyResource');

            $this->html_url = rtrim($this->html_url, '/');
            $this->api_url = filled($this->api_url)
                ? rtrim($this->api_url, '/')
                : $this->gitlabApiUrlFromHtmlUrl($this->html_url);

            $this->validate([
                'name' => 'required|string',
                'html_url' => ['required', 'string', 'url', new SafeExternalUrl],
                'api_url' => ['required', 'string', 'url', new SafeExternalUrl],
                'custom_user' => 'required|string',
                'custom_port' => 'required|int',
                'is_system_wide' => 'required|bool',
                'group_name' => 'nullable|string',
            ]);

            $gitlab_app = GitlabApp::create([
                'name' => $this->name,
                'api_url' => $this->api_url,
                'html_url' => $this->html_url,
                'custom_user' => $this->custom_user,
                'custom_port' => $this->custom_port,
                'is_system_wide' => $this->is_system_wide,
                'group_name' => $this->group_name,
                'webhook_token' => Str::random(32),
                'team_id' => currentTeam()->id,
            ]);

            if (session('from')) {
                session(['from' => session('from') + ['source_id' => $gitlab_app->id]]);
            }

            return redirectRoute($this, 'source.gitlab.show', ['gitlab_app_uuid' => $gitlab_app->uuid]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function gitlabApiUrlFromHtmlUrl(string $htmlUrl): string
    {
        return rtrim($htmlUrl, '/').'/api/v4';
    }
}
