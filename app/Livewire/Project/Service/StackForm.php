<?php

namespace App\Livewire\Project\Service;

use App\Models\Service;
use Illuminate\Support\Collection;
use Livewire\Component;

class StackForm extends Component
{
    public Service $service;

    public Collection $fields;

    protected $listeners = ['saveCompose'];

    public $rules = [
        'service.docker_compose_raw' => 'required',
        'service.docker_compose' => 'required',
        'service.name' => 'required',
        'service.description' => 'nullable',
        'service.connect_to_docker_network' => 'nullable',
    ];

    public $validationAttributes = [];

    public function mount()
    {
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

                $this->rules["fields.$key.value"] = $rules;
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
        $this->service->docker_compose_raw = $raw;
        $this->submit(notify: false);
    }

    public function instantSave()
    {
        $this->service->save();
        $this->dispatch('success', 'Service settings saved.');
    }

    public function submit($notify = true)
    {
        try {
            $this->validate();
            $isValid = validateComposeFile($this->service->docker_compose_raw, $this->service->server->id);
            if ($isValid !== 'OK') {
                throw new \Exception("Invalid docker-compose file.\n$isValid");
            }
            $this->service->save();
            $this->service->saveExtraFields($this->fields);
            $this->service->parse();
            $this->service->refresh();
            $this->service->saveComposeConfigs();
            $this->dispatch('refreshEnvs');
            $notify && $this->dispatch('success', 'Service saved.');
        } catch (\Throwable $e) {
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
