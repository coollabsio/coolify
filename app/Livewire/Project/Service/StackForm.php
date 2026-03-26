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

        if (! $this->isLaravelGitHubStack()) {
            return;
        }

        // Ensure GitHub repository URL is always available as a configurable field
        // for Laravel GitHub-based templates.
        if (! $this->fields->has('SERVICE_GITHUB_REPO_URL')) {
            $githubRepoUrl = $this->service->environment_variables()
                ->where('key', 'SERVICE_GITHUB_REPO_URL')
                ->first();

            $this->fields->put('SERVICE_GITHUB_REPO_URL', [
                'serviceName' => 'SERVICE_GITHUB_REPO_URL',
                'key' => 'SERVICE_GITHUB_REPO_URL',
                'name' => 'GitHub Repo URL',
                'value' => data_get($githubRepoUrl, 'value', ''),
                'isPassword' => false,
                'rules' => 'required|url',
                'customHelper' => 'Public repository URL used to clone your Laravel project.',
            ]);
            $this->validationAttributes['fields.SERVICE_GITHUB_REPO_URL.value'] = 'GitHub Repo URL';
        }

        if (! $this->fields->has('SERVICE_PHP_VERSION')) {
            $phpVersion = $this->service->environment_variables()
                ->where('key', 'SERVICE_PHP_VERSION')
                ->first();

            $this->fields->put('SERVICE_PHP_VERSION', [
                'serviceName' => 'SERVICE_PHP_VERSION',
                'key' => 'SERVICE_PHP_VERSION',
                'name' => 'PHP Version',
                'value' => data_get($phpVersion, 'value', '8.3'),
                'isPassword' => false,
                'rules' => 'required|in:7.4,8.1,8.2,8.3,8.4',
                'customHelper' => 'PHP runtime version used by the Laravel container.',
            ]);
            $this->validationAttributes['fields.SERVICE_PHP_VERSION.value'] = 'PHP Version';
        }

        $websiteUrlFieldKey = $this->fields->has('SERVICE_URL_NGINX_80') ? 'SERVICE_URL_NGINX_80' : 'SERVICE_URL_LARAVEL';
        if (! $this->fields->has($websiteUrlFieldKey)) {
            $serviceUrl = $this->service->environment_variables()
                ->where('key', $websiteUrlFieldKey)
                ->first();

            $this->fields->put($websiteUrlFieldKey, [
                'serviceName' => $websiteUrlFieldKey,
                'key' => $websiteUrlFieldKey,
                'name' => 'Website URL',
                'value' => data_get($serviceUrl, 'value', ''),
                'isPassword' => false,
                'rules' => 'required|string',
                'customHelper' => 'Public URL routed by Coolify for your Laravel app.',
            ]);
            $this->validationAttributes["fields.{$websiteUrlFieldKey}.value"] = 'Website URL';
        }
    }

    public function isLaravelGitHubStack(): bool
    {
        $raw = (string) ($this->dockerComposeRaw ?? $this->service->docker_compose_raw ?? '');

        return str_contains($raw, 'SERVICE_GITHUB_REPO_URL') || str_contains($raw, 'SERVICE_PHP_VERSION');
    }

    public function saveCompose($raw)
    {
        $this->dockerComposeRaw = $raw;
        $this->submit(notify: true);
    }

    public function saveGithubRepoUrl(): void
    {
        $this->submit(notify: false);
        $this->dispatch('success', 'GitHub repository URL saved.');
    }

    public function savePhpVersion(): void
    {
        $this->submit(notify: false);
        $this->dispatch('success', 'PHP version saved.');
    }

    public function saveServiceUrl(): void
    {
        $this->normalizeServiceUrlField();
        $this->submit(notify: false);
        $this->dispatch('success', 'Service URL saved.');
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
            $this->normalizeServiceUrlField();
            $this->validate();
            $this->syncLaravelDatabaseVariable();
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

    private function syncLaravelDatabaseVariable(): void
    {
        $databaseField = $this->fields->get('MYSQL_DATABASE')
            ?? $this->fields->get('SERVICE_DATABASE_MARIADB');

        $databaseName = (string) data_get($databaseField, 'value', '');
        if ($databaseName === '') {
            return;
        }

        $this->fields->put('SERVICE_DATABASE_LARAVEL', [
            'serviceName' => 'SERVICE_DATABASE_LARAVEL',
            'key' => 'SERVICE_DATABASE_LARAVEL',
            'name' => 'Laravel Database Name',
            'value' => $databaseName,
            'isPassword' => false,
            'rules' => 'nullable|string',
            'customHelper' => 'Auto-synced from MariaDB Database Name.',
        ]);
    }

    private function normalizeServiceUrlField(): void
    {
        $serviceUrlFieldKey = $this->fields->has('SERVICE_URL_NGINX_80') ? 'SERVICE_URL_NGINX_80' : 'SERVICE_URL_LARAVEL';
        if (! $this->fields->has($serviceUrlFieldKey)) {
            return;
        }

        $value = trim((string) data_get($this->fields, "{$serviceUrlFieldKey}.value", ''));
        if ($value === '') {
            return;
        }

        if (! str_starts_with($value, 'http://') && ! str_starts_with($value, 'https://')) {
            $value = 'https://'.$value;
        }

        data_set($this->fields, "{$serviceUrlFieldKey}.value", $value);
    }

    public function render()
    {
        return view('livewire.project.service.stack-form');
    }
}
