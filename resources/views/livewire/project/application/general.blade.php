<div>
    <h3>General</h3>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2">
            <x-inputs.input id="application.name" label="Name" required />
            <x-inputs.input id="application.fqdn" label="FQDN" />
            <x-inputs.input id="application.install_command" label="Install Command" />
            <x-inputs.input id="application.build_command" label="Build Command" />
            <x-inputs.input id="application.start_command" label="Start Command" />
            <x-inputs.select id="application.build_pack" label="Build Pack" required>
                <option value="nixpacks">Nixpacks</option>
                <option disabled value="docker">Docker</option>
                <option disabled value="compose">Compose</option>
            </x-inputs.select>
            @if ($application->settings->is_static)
                <x-inputs.select id="application.static_image" label="Static Image" required>
                    <option value="nginx:alpine">nginx:alpine</option>
                    <option disabled value="apache:alpine">apache:alpine</option>
                </x-inputs.select>
            @endif
            <x-inputs.input id="application.base_directory" label="Base Directory" />
            @if ($application->settings->is_static)
                <x-inputs.input id="application.publish_directory" label="Publish Directory" required />
            @else
                <x-inputs.input id="application.publish_directory" label="Publish Directory" />
            @endif
            @if ($application->settings->is_static)
                <x-inputs.input id="application.ports_exposes" label="Ports Exposes" readonly />
            @else
                <x-inputs.input id="application.ports_exposes" label="Ports Exposes" required />
            @endif

            <x-inputs.input id="application.ports_mappings" label="Ports Mappings" />
        </div>
        <x-inputs.button class="mx-auto mt-4 text-white bg-neutral-800 hover:bg-violet-600" type="submit">
            Submit
        </x-inputs.button>
    </form>
    <div class="flex flex-col pt-4">
        <x-inputs.input noDirty instantSave type="checkbox" id="is_static" label="Static website?" />
        <x-inputs.input noDirty instantSave type="checkbox" id="is_git_submodules_allowed"
            label="Git Submodules Allowed?" />
        <x-inputs.input noDirty instantSave type="checkbox" id="is_git_lfs_allowed" label="Git LFS Allowed?" />
        <x-inputs.input noDirty instantSave type="checkbox" id="is_debug" label="Debug" />
        <x-inputs.input noDirty instantSave type="checkbox" id="is_auto_deploy" label="Auto Deploy?" />
        <x-inputs.input noDirty instantSave type="checkbox" id="is_previews" label="Previews?" />
        <x-inputs.input disabled instantSave type="checkbox" id="is_dual_cert" label="Dual Certs?" />
        <x-inputs.input disabled instantSave type="checkbox" id="is_custom_ssl" label="Is Custom SSL?" />
        <x-inputs.input disabled instantSave type="checkbox" id="is_http2" label="Is Http2?" />

    </div>
</div>
