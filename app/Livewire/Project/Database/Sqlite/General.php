<?php

namespace App\Livewire\Project\Database\Sqlite;

use App\Models\Server;
use App\Models\StandaloneSqlite;
use App\Support\ValidationPatterns;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;

    public ?Server $server = null;

    public StandaloneSqlite $database;

    public string $name;

    public ?string $description = null;

    public string $sqliteDatabases;

    public string $image;

    public ?string $customDockerRunOptions = null;

    public bool $isLogDrainEnabled = false;

    public function mount()
    {
        try {
            $this->authorize('view', $this->database);
            $this->syncData();
            $this->server = data_get($this->database, 'destination.server');
            if (! $this->server) {
                $this->dispatch('error', 'Database destination server is not configured.');

                return;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'sqliteDatabases' => ['required', 'string', 'max:255', 'regex:'.StandaloneSqlite::DATABASES_PATTERN],
            'image' => 'required|string',
            'customDockerRunOptions' => 'nullable|string',
            'isLogDrainEnabled' => 'nullable|boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            [
                'sqliteDatabases.required' => 'The Database Files field is required.',
                'sqliteDatabases.regex' => 'The Database Files must be comma-separated file names containing only letters, numbers, dots, dashes and underscores.',
                'image.required' => 'The Docker Image field is required.',
                'image.string' => 'The Docker Image must be a string.',
            ]
        );
    }

    private function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();
            $this->database->name = $this->name;
            $this->database->description = $this->description;
            $this->database->sqlite_databases = $this->sqliteDatabases;
            $this->database->image = $this->image;
            $this->database->custom_docker_run_options = $this->customDockerRunOptions;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->database->save();
        } else {
            $this->name = $this->database->name;
            $this->description = $this->database->description;
            $this->sqliteDatabases = $this->database->sqlite_databases;
            $this->image = $this->database->image;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
        }
    }

    public function instantSaveAdvanced()
    {
        try {
            $this->authorize('update', $this->database);

            if (! $this->server->isLogDrainEnabled()) {
                $this->isLogDrainEnabled = false;
                $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

                return;
            }
            $this->syncData(true);

            $this->dispatch('success', 'Database updated.');
            $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
        } catch (Exception $e) {
            return handleError($e, $this);
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->database);

            $this->sqliteDatabases = collect(explode(',', $this->sqliteDatabases))
                ->map(fn (string $name) => trim($name))
                ->filter(fn (string $name) => $name !== '')
                ->implode(',');
            $this->syncData(true);
            $this->dispatch('success', 'Database updated.');
            $this->dispatch('databaseUpdated');
        } catch (Exception $e) {
            return handleError($e, $this);
        } finally {
            if (is_null($this->database->config_hash)) {
                $this->database->isConfigurationChanged(true);
            } else {
                $this->dispatch('configurationChanged');
            }
        }
    }

    public function refresh(): void
    {
        $this->database->refresh();
        $this->syncData();
    }
}
