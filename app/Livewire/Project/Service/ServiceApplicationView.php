<?php

namespace App\Livewire\Project\Service;

use App\Models\InstanceSettings;
use App\Models\ServiceApplication;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Spatie\Url\Url;

class ServiceApplicationView extends Component
{
    use AuthorizesRequests;

    public ServiceApplication $application;

    public $parameters;

    public $docker_cleanup = true;

    public $delete_volumes = true;

    public $domainConflicts = [];

    public $showDomainConflictModal = false;

    public $forceSaveDomains = false;

    public $showPortWarningModal = false;

    public $forceRemovePort = false;

    public $requiredPort = null;

    #[Validate(['nullable'])]
    public ?string $humanName = null;

    #[Validate(['nullable'])]
    public ?string $description = null;

    #[Validate(['nullable'])]
    public ?string $fqdn = null;

    #[Validate(['string', 'nullable'])]
    public ?string $image = null;

    #[Validate(['required', 'boolean'])]
    public bool $excludeFromStatus = false;

    #[Validate(['nullable', 'boolean'])]
    public bool $isLogDrainEnabled = false;

    #[Validate(['nullable', 'boolean'])]
    public bool $isGzipEnabled = false;

    #[Validate(['nullable', 'boolean'])]
    public bool $isStripprefixEnabled = false;

    protected $rules = [
        'humanName' => 'nullable',
        'description' => 'nullable',
        'fqdn' => 'nullable',
        'image' => 'string|nullable',
        'excludeFromStatus' => 'required|boolean',
        'application.required_fqdn' => 'required|boolean',
        'isLogDrainEnabled' => 'nullable|boolean',
        'isGzipEnabled' => 'nullable|boolean',
        'isStripprefixEnabled' => 'nullable|boolean',
    ];

    public function instantSave()
    {
        try {
            $this->authorize('update', $this->application);
            $this->submit();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSaveAdvanced()
    {
        try {
            $this->authorize('update', $this->application);
            if (! $this->application->service->destination->server->isLogDrainEnabled()) {
                $this->isLogDrainEnabled = false;
                $this->dispatch('error', 'Log drain is not enabled on the server. Please enable it first.');

                return;
            }
            // Sync component properties to model
            $this->application->human_name = $this->humanName;
            $this->application->description = $this->description;
            $this->application->fqdn = $this->fqdn;
            $this->application->image = $this->image;
            $this->application->exclude_from_status = $this->excludeFromStatus;
            $this->application->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->application->is_gzip_enabled = $this->isGzipEnabled;
            $this->application->is_stripprefix_enabled = $this->isStripprefixEnabled;
            $this->application->save();
            $this->dispatch('success', 'You need to restart the service for the changes to take effect.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function delete($password)
    {
        try {
            $this->authorize('delete', $this->application);

            if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
                if (! Hash::check($password, Auth::user()->password)) {
                    $this->addError('password', 'The provided password is incorrect.');

                    return;
                }
            }

            $this->application->delete();
            $this->dispatch('success', 'Application deleted.');

            return redirect()->route('project.service.configuration', $this->parameters);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            $this->authorize('view', $this->application);
            $this->requiredPort = $this->application->getRequiredPort();
            $this->syncData();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function confirmRemovePort()
    {
        $this->forceRemovePort = true;
        $this->showPortWarningModal = false;
        $this->submit();
    }

    public function cancelRemovePort()
    {
        $this->showPortWarningModal = false;
        $this->syncData(); // Reset to original FQDN
    }

    public function syncData(bool $toModel = false): void
    {
        if ($toModel) {
            $this->validate();

            // Sync to model
            $this->application->human_name = $this->humanName;
            $this->application->description = $this->description;
            $this->application->fqdn = $this->fqdn;
            $this->application->image = $this->image;
            $this->application->exclude_from_status = $this->excludeFromStatus;
            $this->application->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->application->is_gzip_enabled = $this->isGzipEnabled;
            $this->application->is_stripprefix_enabled = $this->isStripprefixEnabled;

            $this->application->save();
        } else {
            // Sync from model
            $this->humanName = $this->application->human_name;
            $this->description = $this->application->description;
            $this->fqdn = $this->application->fqdn;
            $this->image = $this->application->image;
            $this->excludeFromStatus = data_get($this->application, 'exclude_from_status', false);
            $this->isLogDrainEnabled = data_get($this->application, 'is_log_drain_enabled', false);
            $this->isGzipEnabled = data_get($this->application, 'is_gzip_enabled', true);
            $this->isStripprefixEnabled = data_get($this->application, 'is_stripprefix_enabled', true);
        }
    }

    public function convertToDatabase()
    {
        try {
            $this->authorize('update', $this->application);
            $service = $this->application->service;
            $serviceApplication = $this->application;

            // Check if database with same name already exists
            if ($service->databases()->where('name', $serviceApplication->name)->exists()) {
                throw new \Exception('A database with this name already exists.');
            }

            $redirectParams = collect($this->parameters)
                ->except('database_uuid')
                ->all();
            DB::transaction(function () use ($service, $serviceApplication) {
                $service->databases()->create([
                    'name' => $serviceApplication->name,
                    'human_name' => $serviceApplication->human_name,
                    'description' => $serviceApplication->description,
                    'exclude_from_status' => $serviceApplication->exclude_from_status,
                    'is_log_drain_enabled' => $serviceApplication->is_log_drain_enabled,
                    'image' => $serviceApplication->image,
                    'service_id' => $service->id,
                    'is_migrated' => true,
                ]);
                $serviceApplication->delete();
            });

            return redirect()->route('project.service.configuration', $redirectParams);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function confirmDomainUsage()
    {
        $this->forceSaveDomains = true;
        $this->showDomainConflictModal = false;
        $this->submit();
    }

    public function submit()
    {
        try {
            $this->authorize('update', $this->application);
            $this->fqdn = str($this->fqdn)->replaceEnd(',', '')->trim()->toString();
            $this->fqdn = str($this->fqdn)->replaceStart(',', '')->trim()->toString();
            $domains = str($this->fqdn)->trim()->explode(',')->map(function ($domain) {
                $domain = trim($domain);
                Url::fromString($domain, ['http', 'https']);

                return str($domain)->lower();
            });
            $this->fqdn = $domains->unique()->implode(',');
            $warning = sslipDomainWarning($this->fqdn);
            if ($warning) {
                $this->dispatch('warning', __('warning.sslipdomain'));
            }
            // Sync to model for domain conflict check (without validation)
            $this->application->human_name = $this->humanName;
            $this->application->description = $this->description;
            $this->application->fqdn = $this->fqdn;
            $this->application->image = $this->image;
            $this->application->exclude_from_status = $this->excludeFromStatus;
            $this->application->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->application->is_gzip_enabled = $this->isGzipEnabled;
            $this->application->is_stripprefix_enabled = $this->isStripprefixEnabled;
            // Check for domain conflicts if not forcing save
            if (! $this->forceSaveDomains) {
                $result = checkDomainUsage(resource: $this->application);
                if ($result['hasConflicts']) {
                    $this->domainConflicts = $result['conflicts'];
                    $this->showDomainConflictModal = true;

                    return;
                }
            } else {
                // Reset the force flag after using it
                $this->forceSaveDomains = false;
            }

            // Check for required port
            if (! $this->forceRemovePort) {
                $requiredPort = $this->application->getRequiredPort();

                if ($requiredPort !== null) {
                    // Check if all FQDNs have a port
                    $fqdns = str($this->fqdn)->trim()->explode(',');
                    $missingPort = false;

                    foreach ($fqdns as $fqdn) {
                        $fqdn = trim($fqdn);
                        if (empty($fqdn)) {
                            continue;
                        }

                        $port = ServiceApplication::extractPortFromUrl($fqdn);
                        if ($port === null) {
                            $missingPort = true;
                            break;
                        }
                    }

                    if ($missingPort) {
                        $this->requiredPort = $requiredPort;
                        $this->showPortWarningModal = true;

                        return;
                    }
                }
            } else {
                // Reset the force flag after using it
                $this->forceRemovePort = false;
            }

            $this->validate();
            $this->application->save();
            $this->application->refresh();
            $this->syncData();
            updateCompose($this->application);
            if (str($this->application->fqdn)->contains(',')) {
                $this->dispatch('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED.<br><br>Only use multiple domains if you know what you are doing.');
            } else {
                ! $warning && $this->dispatch('success', 'Service saved.');
            }
            $this->dispatch('generateDockerCompose');
        } catch (\Throwable $e) {
            $originalFqdn = $this->application->getOriginal('fqdn');
            if ($originalFqdn !== $this->application->fqdn) {
                $this->application->fqdn = $originalFqdn;
                $this->syncData();
            }

            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.service.service-application-view', [
            'checkboxes' => [
                ['id' => 'delete_volumes', 'label' => __('resource.delete_volumes')],
                ['id' => 'docker_cleanup', 'label' => __('resource.docker_cleanup')],
                // ['id' => 'delete_associated_backups_locally', 'label' => 'All backups associated with this Ressource will be permanently deleted from local storage.'],
                // ['id' => 'delete_associated_backups_s3', 'label' => 'All backups associated with this Ressource will be permanently deleted from the selected S3 Storage.'],
                // ['id' => 'delete_associated_backups_sftp', 'label' => 'All backups associated with this Ressource will be permanently deleted from the selected SFTP Storage.']
            ],
        ]);
    }
}
