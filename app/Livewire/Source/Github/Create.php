<?php

namespace App\Livewire\Source\Github;

use App\Models\GithubApp;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public string $name;

    public ?string $organization = null;

    public string $api_url = 'https://api.github.com';

    public string $html_url = 'https://github.com';

    public string $custom_user = 'git';

    public int $custom_port = 22;

    public bool $is_system_wide = false;

    private bool $shouldDeriveApiUrlAfterHtmlUrlUpdate = false;

    public function mount()
    {
        $this->name = substr(generate_random_name(), 0, 30);
    }

    public function updatingHtmlUrl(): void
    {
        $this->shouldDeriveApiUrlAfterHtmlUrlUpdate = blank($this->api_url)
            || $this->api_url === githubApiUrlFromHtmlUrl($this->html_url);
    }

    public function updatedHtmlUrl(): void
    {
        if ($this->shouldDeriveApiUrlAfterHtmlUrlUpdate) {
            $this->api_url = githubApiUrlFromHtmlUrl($this->html_url);
        }
    }

    public function createGitHubApp()
    {
        try {
            $this->authorize('createAnyResource');

            $this->organization = normalizeGithubOrganization($this->organization);
            $this->api_url = filled($this->api_url)
                ? $this->api_url
                : githubApiUrlFromHtmlUrl($this->html_url);

            $this->validate([
                'name' => 'required|string',
                'organization' => ['nullable', 'string', 'regex:/\A[^\s\/?#]+\z/'],
                'api_url' => ['required', 'string', 'url', new SafeExternalUrl],
                'html_url' => ['required', 'string', 'url', new SafeExternalUrl],
                'custom_user' => 'required|string',
                'custom_port' => 'required|int',
                'is_system_wide' => 'required|bool',
            ]);
            $payload = [
                'name' => $this->name,
                'organization' => $this->organization,
                'api_url' => $this->api_url,
                'html_url' => $this->html_url,
                'custom_user' => $this->custom_user,
                'custom_port' => $this->custom_port,
                'is_system_wide' => $this->is_system_wide,
                'team_id' => currentTeam()->id,
            ];
            $github_app = GithubApp::create($payload);
            if (session('from')) {
                session(['from' => session('from') + ['source_id' => $github_app->id]]);
            }

            return redirectRoute($this, 'source.github.show', ['github_app_uuid' => $github_app->uuid]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
