<?php

namespace App\Livewire\Server;

use App\Actions\Server\StartLogDrain;
use App\Actions\Server\StopLogDrain;
use App\Enums\LogDrainType;
use App\Models\Server;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class LogDrains extends Component
{
    use AuthorizesRequests;

    public Server $server;

    /** The active log drain, or null when no log drain is enabled. */
    public ?string $logDrainType = null;

    /** The destination whose fields the form shows. */
    public string $selectedLogDrainType = 'newrelic';

    /** @var array<string, array<string, string|null>> type => field => value */
    public array $logDrainConfig = [];

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    private function syncData(): void
    {
        $settings = $this->server->settings;
        $canUpdate = auth()->user()->can('update', $this->server);

        $this->logDrainType = $settings->logdrain_type?->value;
        $this->selectedLogDrainType = $this->logDrainType ?? $this->selectedLogDrainType;
        $this->logDrainConfig = [];
        foreach (LogDrainType::cases() as $type) {
            $saved = $settings->logDrainConfig($type);
            foreach ($type->driver()->fields() as $key => $field) {
                $value = $saved[$key] ?? null;
                if (($field['secret'] ?? false) && ! $canUpdate) {
                    $value = null;
                }
                $this->logDrainConfig[$type->value][$key] = filled($value) ? (string) $value : ($field['default'] ?? null);
            }
        }
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->server);
            $this->validate($this->rules(LogDrainType::cases(), requireFields: false), [], $this->attributes(LogDrainType::cases()));
            $this->saveConfig();
            $this->auditLogDrain('updated');
            $this->dispatch('success', 'Settings saved.');
        } catch (ValidationException $e) {
            return $this->handleValidationError($e);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function toggleLogDrain(?string $type = null): void
    {
        $previousType = $this->server->settings->logdrain_type;
        $type = LogDrainType::tryFrom($type ?? $this->selectedLogDrainType);

        try {
            $this->authorize('update', $this->server);
            $this->resetErrorBag();
            if (! $type) {
                throw new \InvalidArgumentException('Unknown log drain type.');
            }

            if ($previousType === $type) {
                $this->server->settings->logdrain_type = null;
                $this->server->settings->save();
                $this->auditLogDrain('disabled', $type);
                StopLogDrain::run($this->server);
                $this->dispatch('success', 'Log drain service stopped.');
            } else {
                // Only one drain can be active, so enabling a type replaces the active one.
                $this->validate($this->rules([$type], requireFields: true), [], $this->attributes([$type]));
                $this->server->settings->logdrain_type = $type;
                $this->saveConfig();
                $this->auditLogDrain('enabled', $type);
                StartLogDrain::run($this->server);
                $this->dispatch('success', 'Log drain service started.');
            }
            $this->syncData();
        } catch (ValidationException $e) {
            // Validation runs before anything is saved, so keep the typed values and show the errors under the fields.
            $this->handleValidationError($e);
        } catch (\Throwable $e) {
            // Restore the saved type so the UI and the database never claim a state that
            // StartLogDrain or StopLogDrain failed to apply.
            $this->server->settings->logdrain_type = $previousType;
            $this->server->settings->save();
            $this->syncData();

            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.log-drains', [
            'logDrainTypes' => LogDrainType::options(),
        ]);
    }

    /**
     * Save the typed values of every type. Empty values are not stored.
     */
    private function saveConfig(): void
    {
        $config = [];
        foreach (LogDrainType::cases() as $type) {
            foreach (array_keys($type->driver()->fields()) as $key) {
                $value = data_get($this->logDrainConfig, "{$type->value}.{$key}");
                if (filled($value)) {
                    $config[$type->value][$key] = $value;
                }
            }
        }
        $this->server->settings->logdrain_config = $config === [] ? null : $config;
        $this->server->settings->save();
    }

    /**
     * @param  list<LogDrainType>  $types
     * @return array<string, list<mixed>>
     */
    private function rules(array $types, bool $requireFields): array
    {
        $rules = [];
        foreach ($types as $type) {
            foreach ($type->driver()->fields() as $key => $field) {
                $presence = $requireFields && ($field['required'] ?? false) ? 'required' : 'nullable';
                $rules["logDrainConfig.{$type->value}.{$key}"] = [$presence, ...$field['rules']];
            }
        }

        return $rules;
    }

    /**
     * @param  list<LogDrainType>  $types
     * @return array<string, string>
     */
    private function attributes(array $types): array
    {
        $attributes = [];
        foreach ($types as $type) {
            foreach ($type->driver()->fields() as $key => $field) {
                $attributes["logDrainConfig.{$type->value}.{$key}"] = $field['label'];
            }
        }

        return $attributes;
    }

    private function handleValidationError(ValidationException $e): mixed
    {
        $this->setErrorBag($e->validator->errors());

        return handleError($e, $this);
    }

    private function auditLogDrain(string $action, ?LogDrainType $type = null): void
    {
        auditLog("ui.server.log_drain.{$action}", [
            'team_id' => $this->server->team_id,
            'server_uuid' => $this->server->uuid,
            'server_name' => $this->server->name,
            'provider' => $type?->value,
        ]);
    }
}
