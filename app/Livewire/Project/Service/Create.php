<?php

namespace App\Livewire\Project\Service;

use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceTemplate;
use Livewire\Component;
use Illuminate\Support\Str;

class Create extends Component
{
    public Project $project;
    public $selectedTemplate = null;
    public $serviceName = '';
    public $environmentVariables = [];
    public $customCompose = '';
    public $useTemplate = true;

    protected $rules = [
        'serviceName' => 'required|string|min:1',
        'environmentVariables' => 'array'
    ];

    public function mount(Project $project)
    {
        $this->project = $project;
    }

    public function selectTemplate($templatePath)
    {
        $template = ServiceTemplate::getTemplate($templatePath);
        if ($template) {
            $this->selectedTemplate = $template;
            $this->serviceName = Str::slug($template['name']);
            
            // Initialize environment variables with default values
            $this->environmentVariables = [];
            if (isset($template['environment_variables'])) {
                foreach ($template['environment_variables'] as $envVar) {
                    $this->environmentVariables[$envVar['name']] = $envVar['example'] ?? '';
                }
            }
        }
    }

    public function createService()
    {
        $this->validate();

        $composeContent = $this->customCompose;
        
        if ($this->useTemplate && $this->selectedTemplate) {
            $composeContent = $this->selectedTemplate['compose_content'];
            
            // Replace environment variables in compose content
            foreach ($this->environmentVariables as $key => $value) {
                $composeContent = str_replace('${' . $key . '}', $value, $composeContent);
            }
        }

        $service = Service::create([
            'name' => $this->serviceName,
            'description' => $this->selectedTemplate['description'] ?? '',
            'docker_compose_raw' => $composeContent,
            'project_id' => $this->project->id,
            'environment_id' => $this->project->environment_id,
        ]);

        // Store environment variables
        foreach ($this->environmentVariables as $key => $value) {
            $service->environment_variables()->create([
                'key' => $key,
                'value' => $value,
                'is_preview' => false,
            ]);
        }

        return redirect()->route('project.service.show', [
            'project_uuid' => $this->project->uuid,
            'service_uuid' => $service->uuid,
        ]);
    }

    public function render()
    {
        $templates = ServiceTemplate::getAvailableTemplates();
        
        return view('livewire.project.service.create', [
            'templates' => $templates,
        ]);
    }
}