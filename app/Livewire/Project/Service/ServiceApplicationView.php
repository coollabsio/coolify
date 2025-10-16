<?php

namespace App\Livewire\Project\Service;

use App\Livewire\Concerns\SynchronizesModelData;
use App\Models\InstanceSettings;
use App\Models\ServiceApplication;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Spatie\Url\Url;

class ServiceApplicationView extends Component
{
    use AuthorizesRequests;
    use SynchronizesModelData;

    public ServiceApplication $application;

    public $parameters;

    public $docker_cleanup = true;

    public $delete_volumes = true;

    public $domainConflicts = [];

    public $showDomainConflictModal = false;

    public $forceSaveDomains = false;

    public ?string $humanName = null;

    public ?string $description = null;

    public ?string $fqdn = null;

    public ?string $image = null;

    public bool $excludeFromStatus = false;

    public bool $isLogDrainEnabled = false;

    public bool $isGzipEnabled = false;

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
            $this->syncToModel();
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
            $this->syncFromModel();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function getModelBindings(): array
    {
        return [
            'humanName' => 'application.human_name',
            'description' => 'application.description',
            'fqdn' => 'application.fqdn',
            'image' => 'application.image',
            'excludeFromStatus' => 'application.exclude_from_status',
            'isLogDrainEnabled' => 'application.is_log_drain_enabled',
            'isGzipEnabled' => 'application.is_gzip_enabled',
            'isStripprefixEnabled' => 'application.is_stripprefix_enabled',
        ];
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
            // Sync to model for domain conflict check
            $this->syncToModel();
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

            $this->validate();
            $this->application->save();
            $this->application->refresh();
            $this->syncFromModel();
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
                $this->syncFromModel();
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
