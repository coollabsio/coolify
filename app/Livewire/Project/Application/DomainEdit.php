<?php

namespace App\Livewire\Project\Application;

use App\Models\Application;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Spatie\Url\Url;

class DomainEdit extends Component
{
    use AuthorizesRequests;

    public Application $application;

    public int $index;

    public string $domain;

    public string $scheme = 'https';

    public string $domainName = '';

    public string $port = '';

    public function mount()
    {
        $this->authorize('update', $this->application);

        // Parse the existing domain into scheme, domain, and port
        $this->parseDomain($this->domain);
    }

    protected function parseDomain(string $fullDomain)
    {
        try {
            $url = parse_url($fullDomain);

            // Extract scheme
            $this->scheme = $url['scheme'] ?? 'https';

            // Extract host/path
            $this->domainName = $url['host'] ?? '';
            if (isset($url['path']) && $url['path'] !== '/') {
                $this->domainName .= $url['path'];
            }

            // Extract port
            $this->port = isset($url['port']) ? (string) $url['port'] : '';
        } catch (\Throwable $e) {
            // If parsing fails, just use the domain as-is
            $this->domainName = $fullDomain;
        }
    }

    public function submit()
    {
        $this->authorize('update', $this->application);

        $this->validate([
            'scheme' => 'required|string|alpha_dash',
            'domainName' => 'required|string',
            'port' => 'nullable|integer|min:1|max:65535',
        ]);

        try {
            // Build the full domain URL
            $newDomain = $this->scheme.'://'.trim($this->domainName);

            // Add port if specified
            if (! empty($this->port)) {
                $newDomain .= ':'.$this->port;
            }

            // Clean and validate
            $newDomain = str($newDomain)->replaceEnd(',', '')->trim()->toString();
            $newDomain = str($newDomain)->replaceStart(',', '')->trim()->toString();

            // Validate URL format (allow any scheme)
            try {
                Url::fromString($newDomain);
            } catch (\Throwable $e) {
                $this->dispatch('error', 'Invalid URL format.');

                return;
            }

            $newDomain = str($newDomain)->lower()->toString();

            // Get current domains
            $currentDomains = $this->application->fqdn
                ? array_values(array_filter(explode(',', $this->application->fqdn)))
                : [];

            // Check if domain already exists (but not the one being edited)
            $existingDomains = $currentDomains;
            unset($existingDomains[$this->index]);
            if (in_array($newDomain, $existingDomains)) {
                $this->dispatch('error', 'Domain already exists in the list.');

                return;
            }

            // Update the domain at the specified index
            $currentDomains[$this->index] = $newDomain;

            // Check for domain conflicts
            $this->application->fqdn = implode(',', $currentDomains);
            $result = checkDomainUsage(resource: $this->application);
            if ($result['hasConflicts']) {
                $this->dispatch('domainConflict', [
                    'conflicts' => $result['conflicts'],
                    'editedDomain' => $newDomain,
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
            $this->dispatch('success', 'Domain updated successfully.');
            $this->dispatch('modalClosed');
            $this->dispatch('refreshDomains');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.application.domain-edit');
    }
}
