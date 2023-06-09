<?php

namespace App\Http\Livewire\Project\Application;

use App\Jobs\ApplicationContainerStatusJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use Illuminate\Support\Collection;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Previews extends Component
{
    public Application $application;
    public string $deployment_uuid;
    public array $parameters;
    public Collection $pull_requests;
    public int $rate_limit_remaining;

    public function mount()
    {
        $this->pull_requests = collect();
        $this->parameters = get_parameters();
    }
    public function loadStatus($pull_request_id)
    {
        dispatch(new ApplicationContainerStatusJob(
            application: $this->application,
            container_name: generate_container_name($this->application->uuid, $pull_request_id),
            pull_request_id: $pull_request_id
        ));
    }
    protected function set_deployment_uuid()
    {
        $this->deployment_uuid = new Cuid2(7);
        $this->parameters['deployment_uuid'] = $this->deployment_uuid;
    }
    public function load_prs()
    {
        try {
            ['rate_limit_remaining' => $rate_limit_remaining, 'data' => $data] = get_from_git_api($this->application->source, "/repos/{$this->application->git_repository}/pulls");
            $this->rate_limit_remaining = $rate_limit_remaining;
            $this->pull_requests = $data->sortBy('number')->values();
        } catch (\Throwable $e) {
            $this->rate_limit_remaining = 0;
            return general_error_handler(err: $e, that: $this);
        }
    }
    public function deploy(int $pull_request_id, string|null $pull_request_html_url = null)
    {
        try {
            $this->set_deployment_uuid();
            $found = ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->first();
            if (!$found && !is_null($pull_request_html_url)) {
                ApplicationPreview::create([
                    'application_id' => $this->application->id,
                    'pull_request_id' => $pull_request_id,
                    'pull_request_html_url' => $pull_request_html_url
                ]);
            }
            queue_application_deployment(
                application_id: $this->application->id,
                deployment_uuid: $this->deployment_uuid,
                force_rebuild: true,
                pull_request_id: $pull_request_id,
            );
            return redirect()->route('project.application.deployment', [
                'project_uuid' => $this->parameters['project_uuid'],
                'application_uuid' => $this->parameters['application_uuid'],
                'deployment_uuid' => $this->deployment_uuid,
                'environment_name' => $this->parameters['environment_name'],
            ]);
        } catch (\Throwable $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
    public function stop(int $pull_request_id)
    {
        try {
            $container_name = generate_container_name($this->application->uuid, $pull_request_id);
            ray('Stopping container: ' . $container_name);

            instant_remote_process(["docker rm -f $container_name"], $this->application->destination->server, throwError: false);
            ApplicationPreview::where('application_id', $this->application->id)->where('pull_request_id', $pull_request_id)->delete();
            $this->application->refresh();
        } catch (\Throwable $e) {
            return general_error_handler(err: $e, that: $this);
        }
    }
}
