<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Spatie\Url\Url;

class DomainAdd extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public string $scheme = 'https';

    public string $domain = '';

    public string $port = '';

    public function mount()
    {
        $this->authorize('update', $this->application);
    }

    public function submit()
    {
        $this->authorize('update', $this->application);

        $this->validate([
            'scheme' => 'required|string|alpha_dash',
            'domain' => 'required|string',
            'port' => 'nullable|integer|min:1|max:65535',
        ]);

        try {
            // Build the full domain URL
            $fullDomain = $this->scheme.'://'.trim($this->domain);

            // Add port if specified
            if (! empty($this->port)) {
                $fullDomain .= ':'.$this->port;
            }

            // Clean and validate
            $fullDomain = str($fullDomain)->replaceEnd(',', '')->trim()->toString();
            $fullDomain = str($fullDomain)->replaceStart(',', '')->trim()->toString();

            // Validate URL format (allow any scheme)
            try {
                Url::fromString($fullDomain);
            } catch (\Throwable $e) {
                $this->dispatch('error', 'Invalid URL format.');

                return;
            }

            $fullDomain = str($fullDomain)->lower()->toString();

            // Get current domains
            $currentDomains = $this->application->fqdn
                ? array_values(array_filter(explode(',', $this->application->fqdn)))
                : [];

            // Check if domain already exists
            if (in_array($fullDomain, $currentDomains)) {
                $this->dispatch('error', 'Domain already exists in the list.');

                return;
            }

            // Add to domains array
            $currentDomains[] = $fullDomain;

            // Check for domain conflicts
            $this->application->fqdn = implode(',', $currentDomains);
            $result = checkDomainUsage(resource: $this->application);
            if ($result['hasConflicts']) {
                $this->dispatch('domainConflict', [
                    'conflicts' => $result['conflicts'],
                    'newDomain' => $fullDomain,
                ]);

                return;
            }

            // Save to database
            $this->application->save();

            // Reset default labels
            if ($this->application->settings->is_container_label_readonly_enabled) {
                $customLabels = str(implode('|coolify|', generateLabelsApplication($this->application)))->replace('|coolify|', "\n");
                $this->application->custom_labels = base64_encode($customLabels);
                $this->application->save();
            }

            $this->dispatch('configurationChanged');
            $this->dispatch('success', 'Domain added successfully.');
            $this->dispatch('modalClosed');
            $this->dispatch('refreshDomains');

            // Reset inputs
            $this->scheme = 'https';
            $this->domain = '';
            $this->port = '';
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.domain-add');
    }
}
