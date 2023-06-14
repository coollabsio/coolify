<?php

namespace App\Http\Livewire\Source\Github;

use App\Models\GithubApp;
use App\Models\InstanceSettings;
use Livewire\Component;

class Change extends Component
{
    public string $webhook_endpoint;
    public string|null $ipv4;
    public string|null $ipv6;
    public string|null $fqdn;
    public $parameters;
    public GithubApp $github_app;
    public string $installation_url;
    public string $name;
    public bool $is_system_wide;

    protected $rules = [
        'github_app.name' => 'required|string',
        'github_app.organization' => 'nullable|string',
        'github_app.api_url' => 'required|string',
        'github_app.html_url' => 'required|string',
        'github_app.custom_user' => 'required|string',
        'github_app.custom_port' => 'required|int',
        'github_app.app_id' => 'required|int',
        'github_app.installation_id' => 'nullable',
        'github_app.client_id' => 'nullable',
        'github_app.client_secret' => 'nullable',
        'github_app.webhook_secret' => 'nullable',
        'github_app.is_system_wide' => 'required|bool',
    ];
    public function mount()
    {
        $this->webhook_endpoint = $this->ipv4;
        $this->parameters = get_parameters();
        $this->is_system_wide = $this->github_app->is_system_wide;
    }
    public function submit()
    {
        try {
            $this->validate();
            $this->github_app->save();
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
    public function instantSave()
    {
        try {
            $this->github_app->is_system_wide = $this->is_system_wide;
            $this->github_app->save();
            $this->emit('saved', 'GitHub settings updated!');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }

    public function delete()
    {
        try {
            $this->github_app->delete();
            redirect()->route('dashboard');
        } catch (\Exception $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
