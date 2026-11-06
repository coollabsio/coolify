<?php

namespace App\Livewire\Server\New\Concerns;

use App\Models\InstanceSettings;
use App\Models\Server;

/**
 * The "Scan images for vulnerabilities" choice of the create-server flows.
 *
 * The choice starts from the team default (`teams.scan_images_on_new_servers`) and is saved on the new
 * server. Coolify gets the scanner on the first scan or when an admin presses "Get scanner" on the server.
 */
trait SetsImageScanning
{
    public string $scan_images = 'off';

    public function mountSetsImageScanning(): void
    {
        $this->scan_images = currentTeam()?->scan_images_on_new_servers ? 'on' : 'off';
    }

    protected function applyImageScanning(Server $server): void
    {
        if ($this->scan_images !== 'on' || ! InstanceSettings::get()->is_image_scanning_allowed) {
            return;
        }

        $settings = $server->settings;
        $settings->is_image_scan_enabled = true;
        $settings->saveQuietly();
    }
}
