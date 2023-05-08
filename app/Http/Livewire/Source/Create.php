<?php

namespace App\Http\Livewire\Source;

use App\Models\GithubApp;
use Livewire\Component;

class Create extends Component
{
    public string $name;
    public string|null $organization = null;
    public string $api_url = 'https://api.github.com';
    public string $html_url = 'https://github.com';
    public string $custom_user = 'git';
    public int $custom_port = 22;
    public bool $is_system_wide = false;

    public function mount()
    {
        $this->name = generateRandomName();
    }
    public function createGitHubApp()
    {
        try {
            $this->validate([
                "name" => 'required|string',
                "organization" => 'nullable|string',
                "api_url" => 'required|string',
                "html_url" => 'required|string',
                "custom_user" => 'required|string',
                "custom_port" => 'required|int',
                "is_system_wide" => 'required|bool',
            ]);
            GithubApp::create([
                'name' => $this->name,
                'organization' => $this->organization,
                'api_url' => $this->api_url,
                'html_url' => $this->html_url,
                'custom_user' => $this->custom_user,
                'custom_port' => $this->custom_port,
                'is_system_wide' => $this->is_system_wide,
                'team_id' => session('currentTeam')->id,
            ]);
        } catch (\Exception $e) {
            return generalErrorHandlerLivewire($e, $this);
        }
    }
}
