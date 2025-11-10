<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use App\Support\ValidationPatterns;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class StackForm extends Component
{
    public Service $service;

    public Collection $fields;

    protected $listeners = ['saveCompose'];

    // Explicit properties
    public string $name;

    public ?string $description = null;

    public string $dockerComposeRaw;

    public ?string $dockerCompose = null;

    public ?bool $connectToDockerNetwork = null;

    protected function rules(): array
    {
        $baseRules = [
            'dockerComposeRaw' => 'required',
            'dockerCompose' => 'nullable',
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'connectToDockerNetwork' => 'nullable',
        ];

        // Add dynamic field rules
        foreach ($this->fields ?? collect() as $key => $field) {
            $rules = data_get($field, 'rules', 'nullable');
            $baseRules["fields.$key.value"] = $rules;
        }

        return $baseRules;
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'name.required' => 'The Name field is required.',
                'name.regex' => 'The Name may only contain letters, numbers, spaces, dashes (-), underscores (_), dots (.), slashes (/), colons (:), and parentheses ().',
                'description.regex' => 'The Description contains invalid characters. Only letters, numbers, spaces, and common punctuation (- _ . : / () \' " , ! ? @ # % & + = [] {} | ~ ` *) are allowed.',
                'dockerComposeRaw.required' => 'The Docker Compose Raw field is required.',
                'dockerCompose.required' => 'The Docker Compose field is required.',
            ]
        );
    }

    public $validationAttributes = [];

    /**
     * Sync data between component properties and model
     *
     * @param  bool  $toModel  If true, sync FROM properties TO model. If false, sync FROM model TO properties.
     */
    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            // Sync TO model (before save)
            $this->service->name = $this->name;
            $this->service->description = $this->description;
            $this->service->docker_compose_raw = $this->dockerComposeRaw;
            $this->service->docker_compose = $this->dockerCompose;
            $this->service->connect_to_docker_network = $this->connectToDockerNetwork;
        } else {
            // Sync FROM model (on load/refresh)
            $this->name = $this->service->name;
            $this->description = $this->service->description;
            $this->dockerComposeRaw = $this->service->docker_compose_raw;
            $this->dockerCompose = $this->service->docker_compose;
            $this->connectToDockerNetwork = $this->service->connect_to_docker_network;
        }
    }

    public function mount()
    {
        $this->syncData(false);
        $this->fields = collect([]);
        $extraFields = $this->service->extraFields();
        foreach ($extraFields as $serviceName => $fields) {
            foreach ($fields as $fieldKey => $field) {
                $key = data_get($field, 'key');
                $value = data_get($field, 'value');
                $rules = data_get($field, 'rules', 'nullable');
                $isPassword = data_get($field, 'isPassword', false);
                $customHelper = data_get($field, 'customHelper', false);
                $this->fields->put($key, [
                    'serviceName' => $serviceName,
                    'key' => $key,
                    'name' => $fieldKey,
                    'value' => $value,
                    'isPassword' => $isPassword,
                    'rules' => $rules,
                    'customHelper' => $customHelper,
                ]);

                $this->validationAttributes["fields.$key.value"] = $fieldKey;
            }
        }
        $this->fields = $this->fields->groupBy('serviceName')->map(function ($group) {
            return $group->sortBy(function ($field) {
                return data_get($field, 'isPassword') ? 1 : 0;
            })->mapWithKeys(function ($field) {
                return [$field['key'] => $field];
            });
        })->flatMap(function ($group) {
            return $group;
        });
    }

    public function saveCompose($raw)
    {
        $this->dockerComposeRaw = $raw;
        $this->submit(notify: true);
    }

    public function instantSave()
    {
        $this->syncData(true);
        $this->service->save();
        $this->dispatch('success', 'Service settings saved.');
    }

    public function submit($notify = true)
    {
        try {
            $this->validate();
            $this->syncData(true);

            // Validate for command injection BEFORE any database operations
            validateDockerComposeForInjection($this->service->docker_compose_raw);

            // Use transaction to ensure atomicity - if parse fails, save is rolled back
            DB::transaction(function () {
                $this->service->save();
                $this->service->saveExtraFields($this->fields);
                $this->service->parse();
            });
            // Refresh and write files after a successful commit
            $this->service->refresh();
            $this->service->saveComposeConfigs();

            $this->dispatch('refreshEnvs');
            $this->dispatch('refreshServices');
            $notify && $this->dispatch('success', 'Service saved.');
        } catch (\Throwable $e) {
            // On error, refresh from database to restore clean state
            $this->service->refresh();
            $this->syncData(false);

            return handleError($e, $this);
        } finally {
            if (is_null($this->service->config_hash)) {
                $this->service->isConfigurationChanged(true);
            } else {
                $this->dispatch('configurationChanged');
            }
        }
    }

    public function render()
    {
        return view('livewire.project.service.stack-form');
    }
}
