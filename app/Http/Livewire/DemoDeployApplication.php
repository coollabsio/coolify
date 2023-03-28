<?php

namespace App\Http\Livewire;

use App\Models\Application;
use App\Models\CoolifyInstanceSettings;
use App\Models\Deployment;
use App\Traits\Shared;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class DemoDeployApplication extends Component
{
    use Shared;

    public $activity;
    public $isKeepAliveOn = false;
    public $application_uuid;

    public Application $application;
    public $destination;

    public CoolifyInstanceSettings $coolify_instance_settings;
    public $wildcard_domain;


    public function deploy()
    {
        $this->isKeepAliveOn = true;

        $this->coolify_instance_settings = CoolifyInstanceSettings::find(1);
        $this->application = Application::where('uuid', $this->application_uuid)->first();
        $this->destination = $this->application->destination->getMorphClass()::where('id', $this->application->destination->id)->first();
        $project_wildcard_domain = data_get($this->application, 'environment.project.settings.wildcard_domain');
        $global_wildcard_domain = data_get($this->coolify_instance_settings, 'wildcard_domain');
        $this->wildcard_domain = $project_wildcard_domain ?? $global_wildcard_domain ?? null;

        $source = $this->application->source->getMorphClass()::where('id', $this->application->source->id)->first();
        $deployment_id = new Cuid2(10);

        $workdir = $this->get_workdir('application', $this->application->uuid, $deployment_id);

        $command[] = "echo 'Starting deployment of {$this->application->name} ({$this->application->uuid})'";
        $command[] = 'mkdirs -p ' . $workdir;
        $command[] = "git clone -b {$this->application->git_branch} {$source->html_url}/{$this->application->git_repository}.git {$workdir}";

        if (!file_exists($workdir) && $workdir != "/") {
            $command[] = "echo 'Removing {$workdir}'";
            $command[] = "rm -rf {$workdir}";
        }
        $this->activity = remoteProcess(implode("\n", $command), $this->destination->server->name);

        Deployment::create([
            'uuid' => $deployment_id,
            'type_id' => $this->application->id,
            'type_type' => Application::class,
            'activity_log_id' => $this->activity->id,
        ]);
    }
    public function polling()
    {
        $this->activity?->refresh();
        if (data_get($this->activity, 'properties.exitCode') !== null) {
            $this->isKeepAliveOn = false;
        }
    }
    public function render()
    {
        return view('livewire.demo-deploy-application');
    }
}
