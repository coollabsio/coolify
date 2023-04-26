<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col gap-2 xl:flex-row">
            <div class="flex flex-col w-96">
                <x-form-input id="application.name" label="Name" required />
                <x-form-input id="application.fqdn" label="FQDN" />
            </div>
            <div class="flex flex-col w-96">
                <x-form-input id="application.install_command" label="Install Command" />
                <x-form-input id="application.build_command" label="Build Command" />
                <x-form-input id="application.start_command" label="Start Command" />
                <x-form-input id="application.build_pack" label="Build Pack" />
                @if ($application->settings->is_static)
                    <x-form-input id="application.static_image" label="Static Image" required />
                @endif
            </div>
            <div class="flex flex-col w-96">

                <x-form-input id="application.base_directory" label="Base Directory" />
                @if ($application->settings->is_static)
                    <x-form-input id="application.publish_directory" label="Publish Directory" required />
                @else
                    <x-form-input id="application.publish_directory" label="Publish Directory" />
                @endif

            </div>
            <div class="flex flex-col w-96">
                @if ($application->settings->is_static)
                    <x-form-input id="application.ports_exposes" label="Ports Exposes" disabled />
                @else
                    <x-form-input id="application.ports_exposes" label="Ports Exposes" required />
                @endif

                <x-form-input id="application.ports_mappings" label="Ports Mappings" />
            </div>
        </div>
        <button class="flex mx-auto mt-4" type="submit">
            Submit
        </button>
    </form>
    <div class="flex flex-col pt-4 text-right w-52">
        <x-form-input instantSave type="checkbox" id="is_static" label="Static website?" />
        <x-form-input instantSave type="checkbox" id="is_auto_deploy" label="Auto Deploy?" />
        <x-form-input instantSave type="checkbox" id="is_dual_cert" label="Dual Certs?" />
        <x-form-input instantSave type="checkbox" id="is_previews" label="Previews?" />
        <x-form-input instantSave type="checkbox" id="is_custom_ssl" label="Is Custom SSL?" />
        <x-form-input instantSave type="checkbox" id="is_http2" label="Is Http2?" />
        <x-form-input instantSave type="checkbox" id="is_git_submodules_allowed" label="Git Submodules Allowed?" />
        <x-form-input instantSave type="checkbox" id="is_git_lfs_allowed" label="Git LFS Allowed?" />
        <x-form-input instantSave type="checkbox" id="is_debug" label="Debug" />
    </div>
</div>
