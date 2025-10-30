<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Domains extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public array $domains = [];

    public $domainConflicts = [];

    public $showDomainConflictModal = false;

    public $forceSaveDomains = false;

    public string $redirect;

    protected $listeners = [
        'confirmDomainUsage',
        'refreshDomains' => 'loadDomains',
        'configurationChanged' => '$refresh',
    ];

    public function mount()
    {
        $this->authorize('view', $this->application);

        $this->redirect = $this->application->redirect;
        $this->loadDomains();
    }

    public function loadDomains(): void
    {
        $this->application->refresh();
        if ($this->application->fqdn) {
            $this->domains = array_values(array_filter(
                explode(',', $this->application->fqdn),
                fn ($domain) => ! empty(trim($domain))
            ));
        } else {
            $this->domains = [];
        }
    }

    public function removeDomain(int $index)
    {
        $this->authorize('update', $this->application);

        try {
            if (isset($this->domains[$index])) {
                unset($this->domains[$index]);
                $this->domains = array_values($this->domains); // Re-index array

                $this->saveDomains();

                $this->dispatch('success', 'Domain removed successfully.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function generateDomain()
    {
        $this->authorize('update', $this->application);

        try {
            $server = data_get($this->application, 'destination.server');
            if ($server) {
                $uuid = new Cuid2;
                $fqdn = generateUrl(server: $server, random: $uuid);

                // Check if domain already exists
                if (in_array($fqdn, $this->domains)) {
                    $this->dispatch('error', 'Generated domain already exists.');

                    return;
                }

                $this->domains[] = $fqdn;

                if (! $this->saveDomains()) {
                    return; // Stop if there are conflicts
                }

                $this->dispatch('success', 'Domain generated successfully.');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkDNS(string $domain)
    {
        $this->authorize('view', $this->application);

        try {
            if ($this->application->additional_servers->count() === 0) {
                if (! validateDNSEntry($domain, $this->application->destination->server)) {
                    $this->dispatch('error', 'Validating DNS failed.', "Make sure you have added the DNS records correctly.<br><br>$domain->{$this->application->destination->server->ip}<br><br>Check this <a target='_blank' class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/dns-configuration'>documentation</a> for further help.");
                } else {
                    $this->dispatch('success', 'DNS validation successful.');
                }
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function saveDomains(): bool
    {
        try {
            $this->authorize('update', $this->application);

            // Convert array back to comma-separated string
            $fqdn = implode(',', array_filter($this->domains));

            // Check for domain conflicts if not forcing save
            if (! $this->forceSaveDomains && ! empty($fqdn)) {
                $this->application->fqdn = $fqdn;
                $result = checkDomainUsage(resource: $this->application);
                if ($result['hasConflicts']) {
                    $this->domainConflicts = $result['conflicts'];
                    $this->showDomainConflictModal = true;

                    return false;
                }
            } else {
                // Reset the force flag after using it
                $this->forceSaveDomains = false;
            }

            // Save to database
            $this->application->fqdn = $fqdn ?: null;
            $this->application->save();

            // Reset default labels
            $this->resetDefaultLabels();

            // Reload domains to ensure consistency
            $this->application->refresh();
            $this->loadDomains();

            $this->dispatch('configurationChanged');

            return true;
        } catch (\Throwable $e) {
            handleError($e, $this);

            return false;
        }
    }

    public function confirmDomainUsage()
    {
        $this->forceSaveDomains = true;
        $this->showDomainConflictModal = false;
        $this->saveDomains();
    }

    public function setRedirect()
    {
        $this->authorize('update', $this->application);

        try {
            $has_www = collect($this->application->fqdns)->filter(fn ($fqdn) => str($fqdn)->contains('www.'))->count();
            if ($has_www === 0 && $this->redirect === 'www') {
                $this->dispatch('error', 'You want to redirect to www, but you do not have a www domain set.<br><br>Please add www to your domain list and as an A DNS record (if applicable).');

                return;
            }
            $this->application->redirect = $this->redirect;
            $this->application->save();
            $this->resetDefaultLabels();
            $this->dispatch('success', 'Redirect updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function resetDefaultLabels()
    {
        try {
            if ($this->application->settings->is_container_label_readonly_enabled) {
                $customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
                $this->application->custom_labels = base64_encode($customLabels);
                $this->application->save();
            }
        } catch (\Throwable $e) {
            // Silent fail for label regeneration
        }
    }

    public function render()
    {
        return view('livewire.project.application.domains');
    }
}
