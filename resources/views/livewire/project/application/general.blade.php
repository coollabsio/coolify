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
            </div>
            <div class="flex flex-col w-96">
                <x-form-input id="application.base_directory" label="Base Directory" />
                <x-form-input id="application.publish_directory" label="Publish Directory" />
            </div>
            <div class="flex flex-col w-96">
                <x-form-input id="application.ports_exposes" label="Ports Exposes" />
                <x-form-input id="application.ports_mappings" label="Ports Mappings" />
            </div>
        </div>
        <button class="flex mx-auto mt-4" type="submit">
            Submit
        </button>
    </form>
    <div class="flex flex-col pt-4 text-right w-52">
        <x-form-input instantSave type="checkbox" id="is_auto_deploy" label="Auto Deploy?" />
        <x-form-input instantSave type="checkbox" id="is_dual_cert" label="Dual Certs?" />
        <x-form-input instantSave type="checkbox" id="is_previews" label="Previews?" />
        <x-form-input instantSave type="checkbox" id="is_bot" label="Is Bot?" />
        <x-form-input instantSave type="checkbox" id="is_custom_ssl" label="Is Custom SSL?" />
        <x-form-input instantSave type="checkbox" id="is_http2" label="Is Http2?" />
        <x-form-input instantSave type="checkbox" id="is_git_submodules_allowed" label="Git Submodules Allowed?" />
        <x-form-input instantSave type="checkbox" id="is_git_lfs_allowed" label="Git LFS Allowed?" />
        <x-form-input instantSave type="checkbox" id="is_debug" label="Debug" />
    </div>
</div>
