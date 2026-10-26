<div>
    <x-slot:title>
        Image Scanning | Coolify
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex min-w-0 flex-col gap-6">
            <form wire:submit="saveDatabaseUrl">
                <x-unsaved-bar action="saveDatabaseUrl" targets="imageScanDbUrl" />

                <x-application.settings-section title="Image Scanning"
                    helper="Scan images for known vulnerabilities. Each server turns scanning on in its own settings.">
                    <div class="grid gap-4 md:grid-cols-2">
                        <x-forms.listbox id="isImageScanningAllowed" label="Allow image scanning" onChange="$commit"
                            helper="Off hides image scanning, stops every scan, and turns off Block deploys. Stored results stay."
                            :options="[['value' => '0', 'label' => 'Off'], ['value' => '1', 'label' => 'On']]" />
                        <x-forms.input id="imageScanDbUrl" label="Vulnerability database URL" type="url"
                            placeholder="https://mirror.example.com/grype/databases"
                            helper="An https URL of a Grype database listing, for example a mirror. Leave it empty to use the default." />
                    </div>
                </x-application.settings-section>
            </form>

            <livewire:image-scan.scan-settings scope-type="instance" key="instance-image-scan-settings" />
        </div>
    </x-settings.layout>
</div>
