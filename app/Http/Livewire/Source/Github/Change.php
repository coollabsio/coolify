<?php

namespace App\Http\Livewire\Source\Github;

use App\Models\GithubApp;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Illuminate\Support\Str;

class Change extends Component
{
    public string $host;
    public $parameters;
    public GithubApp $github_app;
    public bool $is_system_wide;

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
    ];
    public function submit()
    {
        try {
            $this->validate();
            $this->github_app->save();
        } catch (\Exception $e) {
            return generalErrorHandlerLivewire($e, $this);
        }
    }
    public function instantSave()
    {
        try {
            $this->github_app->is_system_wide = $this->is_system_wide;
            $this->github_app->save();
        } catch (\Exception $e) {
            return generalErrorHandlerLivewire($e, $this);
        }
    }
    public function mount()
    {
        $this->parameters = getParameters();
        $this->github_app = GithubApp::where('uuid', $this->parameters['github_app_uuid'])->first();
        $this->is_system_wide = $this->github_app->is_system_wide;
    }
    public function createGithubApp()
    {
        $settings = InstanceSettings::first();
        $fqdn = $settings->fqdn;
        if (!$fqdn) {
            $fqdn = $this->host;
        }
        if ($this->github_app->organization) {
            $url = 'organizations/' . $this->github_app->organization . '/settings/apps/new';
        } else {
            $url = 'settings/apps/new';
        }
        $name = Str::kebab('coolify' . $this->github_app->name);
        $data = [
            "name" => $name,
            "url" => $fqdn,
            "hook_attributes" => [
                "url" => "$fqdn/webhooks/github/events"
            ],
            "redirect_url" => "$fqdn/webhooks/github",
            "callback_url" => [
                "$fqdn/login/github/app",
            ],
            "public" => false,
            "request_oauth_on_install" => false,
            "setup_url" => "$fqdn/webhooks/github/install?source_id=" . $this->github_app->uuid,
            "setup_on_update" => true,
            "default_permissions" => [
                "contents" => 'read',
                "metadata" => 'read',
                "pull_requests" => 'read',
                "emails" => 'read'
            ],
            "default_events" => ['pull_request', 'push']
        ];
        $response = Http::asForm()->post("{$this->github_app->html_url}/{$url}?state={$this->github_app->uuid}", [
            'id' => 'manifest',
            'name' => 'manifest',
            'data' => json_encode($data),
        ]);
        dd($response);
    }
}
