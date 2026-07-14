<?php

namespace App\Livewire\Source\Gitlab;

use App\Models\GitlabApp;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Livewire\Component;

class Create extends Component
{
    use AuthorizesRequests;

    public string $name;

    public string $html_url = 'https://gitlab.com';

    public bool $is_system_wide = false;

    public ?string $group_name = null;

    public function mount()
    {
        $this->name = substr(generate_random_name(), 0, 30);
    }

    public function createGitLabApp()
    {
        try {
            $this->authorize('createAnyResource');

            $this->validate([
                'name' => 'required|string',
                'html_url' => ['required', 'string', 'url', new SafeExternalUrl],
                'is_system_wide' => 'required|bool',
                'group_name' => 'nullable|string',
            ]);

            $htmlUrl = rtrim($this->html_url, '/');
            $apiUrl = $htmlUrl.'/api/v4';

            $gitlab_app = GitlabApp::create([
                'name' => $this->name,
                'api_url' => $apiUrl,
                'html_url' => $htmlUrl,
                'is_system_wide' => $this->is_system_wide,
                'group_name' => $this->group_name,
                'webhook_token' => Str::random(32),
                'team_id' => currentTeam()->id,
            ]);

            if (session('from')) {
                session(['from' => session('from') + ['source_id' => $gitlab_app->id]]);
            }

            return redirectRoute($this, 'source.gitlab.show', ['gitlab_app_uuid' => $gitlab_app->uuid]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
