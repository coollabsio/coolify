<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex flex-col xl:flex-row gap-2">
            <div class="flex-col flex w-96">
                <x-input name="application.name" label="Name" required />
                <x-input name="application.fqdn" label="FQDN" />
            </div>
            <div class="flex-col flex w-96">
                <x-input name="application.install_command" label="Install Command" />
                <x-input name="application.build_command" label="Build Command" />
                <x-input name="application.start_command" label="Start Command" />
                <x-input name="application.build_pack" label="Build Pack" />
            </div>
            <div class="flex-col flex w-96">
                <x-input name="application.base_directory" label="Base Directory" />
                <x-input name="application.publish_directory" label="Publish Directory" />
            </div>
            <div class="flex-col flex w-96">
                <x-input name="application.ports_exposes" label="Ports Exposes" />
            </div>
        </div>
        <button class="flex mx-auto mt-4" type="submit">
            Submit
        </button>
    </form>
    <div class="flex flex-col pt-4 w-52 text-right">
        <x-input instantSave type="checkbox" name="is_auto_deploy" label="Auto Deploy?" />
        <x-input instantSave type="checkbox" name="is_dual_cert" label="Dual Certs?" />
        <x-input instantSave type="checkbox" name="is_previews" label="Previews?" />
        <x-input instantSave type="checkbox" name="is_bot" label="Is Bot?" />
        <x-input instantSave type="checkbox" name="is_custom_ssl" label="Is Custom SSL?" />
        <x-input instantSave type="checkbox" name="is_http2" label="Is Http2?" />
        <x-input instantSave type="checkbox" name="is_git_submodules_allowed" label="Git Submodules Allowed?" />
        <x-input instantSave type="checkbox" name="is_git_lfs_allowed" label="Git LFS Allowed?" />
        <x-input instantSave type="checkbox" name="is_debug" label="Debug" />
    </div>
</div>
