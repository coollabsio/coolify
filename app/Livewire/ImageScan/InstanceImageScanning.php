<?php

namespace App\Livewire\ImageScan;

use App\Models\InstanceSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Instance settings page for image scanning: the instance switch, the vulnerability database URL, and the default rules.
 */
class InstanceImageScanning extends Component
{
    public ?string $isImageScanningAllowed = '1';

    public ?string $imageScanDbUrl = null;

    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }

        $settings = InstanceSettings::get();
        $this->isImageScanningAllowed = $settings->is_image_scanning_allowed ? '1' : '0';
        $this->imageScanDbUrl = $settings->image_scan_db_url;
    }

    public function updatedIsImageScanningAllowed(): void
    {
        abort_unless(isInstanceAdmin(), 403);
        $this->validate(['isImageScanningAllowed' => ['required', 'in:1,0']]);

        $this->saveSettings(['is_image_scanning_allowed' => $this->isImageScanningAllowed === '1']);
    }

    public function saveDatabaseUrl(): void
    {
        abort_unless(isInstanceAdmin(), 403);
        $this->imageScanDbUrl = filled($this->imageScanDbUrl) ? trim($this->imageScanDbUrl) : null;
        $this->validate(
            ['imageScanDbUrl' => ['nullable', 'url:https', 'max:2048', 'regex:#\Ahttps://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~%/+=-]*)?\z#']],
            ['imageScanDbUrl.regex' => 'Use an https URL without a query, a fragment, or credentials.'],
        );

        $this->saveSettings(['image_scan_db_url' => $this->imageScanDbUrl]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function saveSettings(array $values): void
    {
        $settings = InstanceSettings::get();
        $settings->fill($values);
        $changedFields = auditChangedFields($settings);
        $settings->save();
        if ($changedFields !== []) {
            auditLog('ui.instance.settings.updated', [
                'team_id' => null,
                'resource' => 'instance',
                'section' => 'image_scanning',
                'changed_fields' => $changedFields,
            ]);
        }
        $this->dispatch('success', 'Saved.');
    }

    public function render(): View
    {
        return view('livewire.image-scan.instance-image-scanning');
    }
}
