<?php

namespace App\Livewire\Project\New;

use App\Models\Project;
use App\Models\Service;
use Livewire\Component;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ExternalService extends Component
{
    public $project_uuid;
    public $environment_uuid;
    public $git_url;
    public $github_token;
    public $message;

    protected $rules = [
        'git_url' => 'required|url',
    ];

    public function mount()
    {
        $this->project_uuid = request()->route('project_uuid');
        $this->environment_uuid = request()->route('environment_uuid');
    }

    public function submit()
    {
        $this->validate();

        try {
            // Basic support for public GitHub repos
            // Convert https://github.com/user/repo to raw content URL for docker-compose.yml
            $rawUrl = $this->git_url;
            if (Str::contains($this->git_url, 'github.com')) {
                $rawUrl = Str::replace('github.com', 'raw.githubusercontent.com', $this->git_url);
                if (!Str::contains($rawUrl, '/main/') && !Str::contains($rawUrl, '/master/')) {
                    $rawUrl .= '/main/docker-compose.yml';
                } else {
                    $rawUrl .= '/docker-compose.yml';
                }
            }

            $response = Http::get($rawUrl);
            if ($response->failed()) {
                throw new \Exception("Could not fetch docker-compose.yml from {$rawUrl}");
            }

            $composeContent = $response->body();
            $project = Project::where('uuid', $this->project_uuid)->first();
            $environment = $project->environments()->where('uuid', $this->environment_uuid)->first();

            $service = Service::create([
                'name' => Str::slug(Str::afterLast($this->git_url, '/')),
                'docker_compose_raw' => $composeContent,
                'environment_id' => $environment->id,
                'server_id' => $environment->project->destination->server_id,
                'destination_id' => $environment->project->destination->id,
            ]);

            return redirect()->route('project.service.configuration', [
                'project_uuid' => $this->project_uuid,
                'environment_uuid' => $this->environment_uuid,
                'service_uuid' => $service->uuid,
            ]);

        } catch (\Exception $e) {
            $this->message = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.project.new.external-service');
    }
}
