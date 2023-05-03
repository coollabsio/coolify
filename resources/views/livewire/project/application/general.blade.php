<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-inputs.input id="application.name" label="Name" required />
                <x-inputs.input id="application.fqdn" label="FQDN" />
            </div>
            <div class="flex flex-col w-96">
                <x-inputs.input id="application.install_command" label="Install Command" />
                <x-inputs.input id="application.build_command" label="Build Command" />
                <x-inputs.input id="application.start_command" label="Start Command" />
                <x-inputs.input id="application.build_pack" label="Build Pack" />
                @if ($application->settings->is_static)
                    <x-inputs.input id="application.static_image" label="Static Image" required />
                @endif
            </div>
            <div class="flex flex-col w-96">

                <x-inputs.input id="application.base_directory" label="Base Directory" />
                @if ($application->settings->is_static)
                    <x-inputs.input id="application.publish_directory" label="Publish Directory" required />
                @else
                    <x-inputs.input id="application.publish_directory" label="Publish Directory" />
                @endif

            </div>
            <div class="flex flex-col w-96">
                @if ($application->settings->is_static)
                    <x-inputs.input id="application.ports_exposes" label="Ports Exposes" disabled />
                @else
                    <x-inputs.input id="application.ports_exposes" label="Ports Exposes" required />
                @endif

                <x-inputs.input id="application.ports_mappings" label="Ports Mappings" />
            </div>
        </div>
        <x-inputs.button class="flex mx-auto mt-4" type="submit">
            Submit
        </x-inputs.button>
    </form>
    <div class="flex flex-col pt-4 text-right w-52">
        <x-inputs.input instantSave type="checkbox" id="is_static" label="Static website?" />
        <x-inputs.input instantSave type="checkbox" id="is_auto_deploy" label="Auto Deploy?" />
        <x-inputs.input instantSave type="checkbox" id="is_dual_cert" label="Dual Certs?" />
        <x-inputs.input instantSave type="checkbox" id="is_previews" label="Previews?" />
        <x-inputs.input instantSave type="checkbox" id="is_custom_ssl" label="Is Custom SSL?" />
        <x-inputs.input instantSave type="checkbox" id="is_http2" label="Is Http2?" />
        <x-inputs.input instantSave type="checkbox" id="is_git_submodules_allowed" label="Git Submodules Allowed?" />
        <x-inputs.input instantSave type="checkbox" id="is_git_lfs_allowed" label="Git LFS Allowed?" />
        <x-inputs.input instantSave type="checkbox" id="is_debug" label="Debug" />
    </div>
</div>
