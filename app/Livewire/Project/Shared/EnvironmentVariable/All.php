<?php

namespace App\Livewire\Project\Shared\EnvironmentVariable;

use App\Models\EnvironmentVariable;
use App\Traits\EnvironmentVariableProtection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class All extends Component
{
    use AuthorizesRequests, EnvironmentVariableProtection;

    public $resource;

    public string $resourceClass;

    public bool $showPreview = false;

    public ?string $variables = null;

    public ?string $variablesPreview = null;

    public string $view = 'normal';

    public bool $is_env_sorting_enabled = false;

    public bool $use_build_secrets = false;

    protected $listeners = [
        'saveKey' => 'submit',
        'refreshEnvs',
        'environmentVariableDeleted' => 'refreshEnvs',
    ];

    public function mount()
    {
        $this->is_env_sorting_enabled = data_get($this->resource, 'settings.is_env_sorting_enabled', false);
        $this->use_build_secrets = data_get($this->resource, 'settings.use_build_secrets', false);
        $this->resourceClass = get_class($this->resource);
        $resourceWithPreviews = [\App\Models\Application::class];
        $simpleDockerfile = filled(data_get($this->resource, 'dockerfile'));
        if (str($this->resourceClass)->contains($resourceWithPreviews) && ! $simpleDockerfile) {
            $this->showPreview = true;
        }
        $this->getDevView();
    }

    public function instantSave()
    {
        try {
            $this->authorize('manageEnvironment', $this->resource);

            $this->resource->settings->is_env_sorting_enabled = $this->is_env_sorting_enabled;
            $this->resource->settings->use_build_secrets = $this->use_build_secrets;
            $this->resource->settings->save();
            $this->getDevView();
            $this->dispatch('success', 'Environment variable settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getEnvironmentVariablesProperty()
    {
        if ($this->is_env_sorting_enabled === false) {
            return $this->resource->environment_variables()->orderBy('order')->get();
        }

        return $this->resource->environment_variables;
    }

    public function getEnvironmentVariablesPreviewProperty()
    {
        if ($this->is_env_sorting_enabled === false) {
            return $this->resource->environment_variables_preview()->orderBy('order')->get();
        }

        return $this->resource->environment_variables_preview;
    }

    public function getDevView()
    {
        $this->variables = $this->formatEnvironmentVariables($this->environmentVariables);
        if ($this->showPreview) {
            $this->variablesPreview = $this->formatEnvironmentVariables($this->environmentVariablesPreview);
        }
    }

    private function formatEnvironmentVariables($variables)
    {
        return $variables->map(function ($item) {
            if ($item->is_shown_once) {
                return "$item->key=(Locked Secret, delete and add again to change)";
            }
            if ($item->is_multiline) {
                return "$item->key=(Multiline environment variable, edit in normal view)";
            }

            return "$item->key=$item->value";
        })->join("\n");
    }

    public function switch()
    {
        $this->view = $this->view === 'normal' ? 'dev' : 'normal';
        $this->getDevView();
    }

    public function submit($data = null)
    {
        try {
            $this->authorize('manageEnvironment', $this->resource);
            if ($data === null) {
                $this->handleBulkSubmit();
            } else {
                $this->handleSingleSubmit($data);
            }

            $this->updateOrder();
            $this->getDevView();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->refreshEnvs();
        }
    }

    private function updateOrder()
    {
        $variables = parseEnvFormatToArray($this->variables);
        $order = 1;
        foreach ($variables as $key => $value) {
            $env = $this->resource->environment_variables()->where('key', $key)->first();
            if ($env) {
                $env->order = $order;
                $env->save();
            }
            $order++;
        }

        if ($this->showPreview) {
            $previewVariables = parseEnvFormatToArray($this->variablesPreview);
            $order = 1;
            foreach ($previewVariables as $key => $value) {
                $env = $this->resource->environment_variables_preview()->where('key', $key)->first();
                if ($env) {
                    $env->order = $order;
                    $env->save();
                }
                $order++;
            }
        }
    }

    private function handleBulkSubmit()
    {
        $variables = parseEnvFormatToArray($this->variables);
        $changesMade = false;
        $errorOccurred = false;

        // Try to delete removed variables
        $deletedCount = $this->deleteRemovedVariables(false, $variables);
        if ($deletedCount > 0) {
            $changesMade = true;
        } elseif ($deletedCount === 0 && $this->resource->environment_variables()->whereNotIn('key', array_keys($variables))->exists()) {
            // If we tried to delete but couldn't (due to Docker Compose), mark as error
            $errorOccurred = true;
        }

        // Update or create variables
        $updatedCount = $this->updateOrCreateVariables(false, $variables);
        if ($updatedCount > 0) {
            $changesMade = true;
        }

        if ($this->showPreview) {
            $previewVariables = parseEnvFormatToArray($this->variablesPreview);

            // Try to delete removed preview variables
            $deletedPreviewCount = $this->deleteRemovedVariables(true, $previewVariables);
            if ($deletedPreviewCount > 0) {
                $changesMade = true;
            } elseif ($deletedPreviewCount === 0 && $this->resource->environment_variables_preview()->whereNotIn('key', array_keys($previewVariables))->exists()) {
                // If we tried to delete but couldn't (due to Docker Compose), mark as error
                $errorOccurred = true;
            }

            // Update or create preview variables
            $updatedPreviewCount = $this->updateOrCreateVariables(true, $previewVariables);
            if ($updatedPreviewCount > 0) {
                $changesMade = true;
            }
        }

        // Only show success message if changes were actually made and no errors occurred
        if ($changesMade && ! $errorOccurred) {
            $this->dispatch('success', 'Environment variables updated.');
        }
    }

    private function handleSingleSubmit($data)
    {
        $found = $this->resource->environment_variables()->where('key', $data['key'])->first();
        if ($found) {
            $this->dispatch('error', 'Environment variable already exists.');

            return;
        }

        $maxOrder = $this->resource->environment_variables()->max('order') ?? 0;
        $environment = $this->createEnvironmentVariable($data);
        $environment->order = $maxOrder + 1;
        $environment->save();

        // Clear computed property cache to force refresh
        unset($this->environmentVariables);
        unset($this->environmentVariablesPreview);

        $this->dispatch('success', 'Environment variable added.');
    }

    private function createEnvironmentVariable($data)
    {
        $environment = new EnvironmentVariable;
        $environment->key = $data['key'];
        $environment->value = $data['value'];
        $environment->is_multiline = $data['is_multiline'] ?? false;
        $environment->is_literal = $data['is_literal'] ?? false;
        $environment->is_runtime = $data['is_runtime'] ?? true;
        $environment->is_buildtime = $data['is_buildtime'] ?? true;
        $environment->is_preview = $data['is_preview'] ?? false;
        $environment->resourceable_id = $this->resource->id;
        $environment->resourceable_type = $this->resource->getMorphClass();

        return $environment;
    }

    private function deleteRemovedVariables($isPreview, $variables)
    {
        $method = $isPreview ? 'environment_variables_preview' : 'environment_variables';

        // Get all environment variables that will be deleted
        $variablesToDelete = $this->resource->$method()->whereNotIn('key', array_keys($variables))->get();

        // If there are no variables to delete, return 0
        if ($variablesToDelete->isEmpty()) {
            return 0;
        }

        // Check if any of these variables are used in Docker Compose
        if ($this->resource->type() === 'service' || $this->resource->build_pack === 'dockercompose') {
            foreach ($variablesToDelete as $envVar) {
                [$isUsed, $reason] = $this->isEnvironmentVariableUsedInDockerCompose($envVar->key, $this->resource->docker_compose);

                if ($isUsed) {
                    $this->dispatch('error', "Cannot delete environment variable '{$envVar->key}' <br><br>Please remove it from the Docker Compose file first.");

                    return 0;
                }
            }
        }

        // If we get here, no variables are used in Docker Compose, so we can delete them
        $this->resource->$method()->whereNotIn('key', array_keys($variables))->delete();

        return $variablesToDelete->count();
    }

    private function updateOrCreateVariables($isPreview, $variables)
    {
        $count = 0;
        foreach ($variables as $key => $value) {
            if (str($key)->startsWith('SERVICE_FQDN') || str($key)->startsWith('SERVICE_URL') || str($key)->startsWith('SERVICE_NAME')) {
                continue;
            }
            $method = $isPreview ? 'environment_variables_preview' : 'environment_variables';
            $found = $this->resource->$method()->where('key', $key)->first();

            if ($found) {
                if (! $found->is_shown_once && ! $found->is_multiline) {
                    // Only count as a change if the value actually changed
                    if ($found->value !== $value) {
                        $found->value = $value;
                        $found->save();
                        $count++;
                    }
                }
            } else {
                $environment = new EnvironmentVariable;
                $environment->key = $key;
                $environment->value = $value;
                $environment->is_multiline = false;
                $environment->is_preview = $isPreview;
                $environment->resourceable_id = $this->resource->id;
                $environment->resourceable_type = $this->resource->getMorphClass();

                $environment->save();
                $count++;
            }
        }

        return $count;
    }

    public function refreshEnvs()
    {
        $this->resource->refresh();
        // Clear computed property cache to force refresh
        unset($this->environmentVariables);
        unset($this->environmentVariablesPreview);
        $this->getDevView();
    }

    public function loadFromEnvExample()
    {
        try {
            $this->authorize('manageEnvironment', $this->resource);

            if ($this->resourceClass !== 'App\Models\Application') {
                throw new \RuntimeException('This feature is only available for applications.');
            }

            if (! $this->canLoadEnvExample) {
                throw new \RuntimeException('This feature requires a git repository source.');
            }

            $envExampleContent = $this->fetchEnvExampleFromGit();

            if ($envExampleContent === null) {
                $this->dispatch('error', 'No .env.example file found in the repository.');

                return;
            }

            $variables = parseEnvFormatToArray($envExampleContent);

            if (empty($variables)) {
                $this->dispatch('warning', 'The .env.example file is empty or contains no valid variables.');

                return;
            }

            $addedCount = 0;
            $skippedCount = 0;
            $maxOrder = $this->resource->environment_variables()->max('order') ?? 0;

            foreach ($variables as $key => $value) {
                if (str($key)->startsWith('SERVICE_FQDN') ||
                    str($key)->startsWith('SERVICE_URL') ||
                    str($key)->startsWith('SERVICE_NAME')) {
                    $skippedCount++;

                    continue;
                }

                $exists = $this->resource->environment_variables()->where('key', $key)->exists();
                if ($exists) {
                    $skippedCount++;

                    continue;
                }

                $environment = new EnvironmentVariable;
                $environment->key = $key;
                $environment->value = $value;
                $environment->is_multiline = false;
                $environment->is_preview = false;
                $environment->is_runtime = true;
                $environment->is_buildtime = true;
                $environment->resourceable_id = $this->resource->id;
                $environment->resourceable_type = $this->resource->getMorphClass();
                $environment->order = ++$maxOrder;
                $environment->save();

                $addedCount++;
            }

            $this->refreshEnvs();

            if ($addedCount > 0) {
                $message = "Added {$addedCount} environment variable(s)";
                if ($skippedCount > 0) {
                    $message .= ", skipped {$skippedCount} existing";
                }
                $this->dispatch('success', $message.'.');
            } else {
                $this->dispatch('warning', 'All variables from .env.example already exist.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function fetchEnvExampleFromGit(): ?string
    {
        $uuid = (string) new Cuid2;

        ['commands' => $cloneCommand] = $this->resource->generateGitImportCommands(
            deployment_uuid: $uuid,
            only_checkout: true,
            exec_in_docker: false,
            custom_base_dir: '.'
        );

        $workdir = rtrim($this->resource->base_directory ?? '', '/');
        $envFile = '.env.example';
        $fileList = collect([".$workdir/$envFile"]);

        $server = $this->resource->destination->server;

        $getGitVersion = instant_remote_process(['git --version'], $server, false);
        $gitVersion = str($getGitVersion)->explode(' ')->last();

        if (version_compare($gitVersion, '2.35.1', '<')) {
            $fileList = $fileList->map(function ($file) {
                $parts = explode('/', trim($file, '.'));
                $paths = collect();
                $currentPath = '';
                foreach ($parts as $part) {
                    $currentPath .= ($currentPath ? '/' : '').$part;
                    if (str($currentPath)->isNotEmpty()) {
                        $paths->push($currentPath);
                    }
                }

                return $paths;
            })->flatten()->unique()->values();

            $commands = collect([
                "rm -rf /tmp/{$uuid}",
                "mkdir -p /tmp/{$uuid}",
                "cd /tmp/{$uuid}",
                $cloneCommand,
                'git sparse-checkout init',
                "git sparse-checkout set {$fileList->implode(' ')}",
                'git read-tree -mu HEAD',
                "cat .$workdir/$envFile",
            ]);
        } else {
            $commands = collect([
                "rm -rf /tmp/{$uuid}",
                "mkdir -p /tmp/{$uuid}",
                "cd /tmp/{$uuid}",
                $cloneCommand,
                'git sparse-checkout init --cone',
                "git sparse-checkout set {$fileList->implode(' ')}",
                'git read-tree -mu HEAD',
                "cat .$workdir/$envFile",
            ]);
        }

        try {
            $content = instant_remote_process($commands, $server);

            return $content;
        } catch (\Exception $e) {
            if (str($e->getMessage())->contains('No such file')) {
                return null;
            }
            throw $e;
        } finally {
            instant_remote_process(["rm -rf /tmp/{$uuid}"], $server, false);
        }
    }

    public function getCanLoadEnvExampleProperty(): bool
    {
        if ($this->resourceClass !== 'App\Models\Application') {
            return false;
        }

        if (filled(data_get($this->resource, 'dockerfile'))) {
            return false;
        }

        $deploymentType = $this->resource->deploymentType();

        return in_array($deploymentType, ['source', 'deploy_key']);
    }
}
