<div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="name" />
            <x-forms.input label="Description" id="description" />
            <x-forms.input label="Image" id="image" required />
        </div>
        <x-forms.input
            helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/custom-commands'>docs.</a>"
            placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k"
            id="customDockerRunOptions" label="Custom Docker Options" />

        @if ($database->started_at)
            <div class="flex gap-2">
                <x-forms.input label="Initial Password" id="dragonflyPassword" type="password" required readonly
                    helper="You can only change this in the database." />
            </div>
        @else
            <div class=" dark:text-warning">Please verify these values. You can only modify them before the initial
                start. After that, you need to modify it in the database.
            </div>
            <div class="flex gap-2">
                <x-forms.input label="Password" id="dragonflyPassword" type="password" required />
            </div>
        @endif
        <div class="flex flex-col gap-2">
            <h3 class="py-2">Network</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="3000:5432" id="portsMappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system.<br><span class='inline-block font-bold dark:text-warning'>Example</span>3000:5432,3002:5433" />
            </div>
            <x-forms.input label="Dragonfly URL (internal)"
                helper="If you change the user/password/port, this could be different. This is with the default values."
                type="password" readonly wire:model="dbUrl" />

            @if ($dbUrlPublic)
                <x-forms.input label="Dragonfly URL (public)"
                    helper="If you change the user/password/port, this could be different. This is with the default values."
                    type="password" readonly wire:model="dbUrlPublic" />
            @else
                <x-forms.input label="Dragonfly URL (public)"
                    helper="If you change the user/password/port, this could be different. This is with the default values."
                    readonly value="Starting the database will generate this." />
            @endif
        </div>
        <div>
            <div class="flex flex-col py-2 w-64">
                <div class="flex items-center gap-2 pb-2">
                    <div class="flex items-center">
                        <h3>Proxy</h3>
                        <x-loading wire:loading wire:target="instantSave" />
                    </div>
                    @if ($isPublic)
                        <x-slide-over fullScreen>
                            <x-slot:title>Proxy Logs</x-slot:title>
                            <x-slot:content>
                                <livewire:project.shared.get-logs :server="$server" :resource="$database"
                                    container="{{ data_get($database, 'uuid') }}-proxy" lazy />
                            </x-slot:content>
                            <x-forms.button disabled="{{ !$isPublic }}"
                                @click="slideOverOpen=true">Logs</x-forms.button>
                        </x-slide-over>
                    @endif
                </div>
                <x-forms.checkbox instantSave id="isPublic" label="Make it publicly available" />
            </div>
            <x-forms.input placeholder="5432" disabled="{{ $isPublic }}" id="publicPort" label="Public Port" />
        </div>
    </form>
    <h3 class="pt-4">Advanced</h3>
    <div class="w-64">
        <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
            instantSave="instantSaveAdvanced" id="isLogDrainEnabled" label="Drain Logs" />
    </div>
</div>
