@if (\App\Models\InstanceSettings::get()->is_image_scanning_allowed)
    <x-application.settings-section title="Image scanning"
        description="Scan the images on this server for known vulnerabilities. You can change this later in the security settings of the server.">
        <x-forms.listbox id="scan_images" label="Scan images for vulnerabilities"
            helper="Scans use up to 1 CPU and 1 GB of memory while they run, and about 1.2 GB of disk, plus temporary space for one image."
            :options="[
                ['value' => 'off', 'label' => 'Off'],
                ['value' => 'on', 'label' => 'On'],
            ]" />
    </x-application.settings-section>
@endif
