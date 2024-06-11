<div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="database.name" />
            <x-forms.input label="Description" id="database.description" />
            <x-forms.input label="Image" id="database.image" required
                helper="For all available images, check here:<br><br><a target='_blank' href=https://hub.docker.com/r/eqalpha/keydb'>https://hub.docker.com/r/eqalpha/keydb</a>" />
        </div>
        <div class="flex flex-col gap-2">
            <h3 class="py-2">Network</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="3000:5432" id="database.ports_mappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system.<br><span class='inline-block font-bold dark:text-warning'>Example</span>3000:5432,3002:5433" />
            </div>
            <x-forms.input label="KeyDB URL (internal)"
                helper="If you change the user/password/port, this could be different. This is with the default values."
                type="password" readonly wire:model="db_url" />
            @if ($db_url_public)
                <x-forms.input label="KeyDB URL (public)"
                    helper="If you change the user/password/port, this could be different. This is with the default values."
                    type="password" readonly wire:model="db_url_public" />
            @endif
        </div>
        <div>
            <h3 class="py-2">Proxy</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="5432" disabled="{{ data_get($database, 'is_public') }}"
                    id="database.public_port" label="Public Port" />
                <x-slide-over fullScreen>
                    <x-slot:title>Proxy Logs</x-slot:title>
                    <x-slot:content>
                        <livewire:project.shared.get-logs :server="$server" :resource="$database"
                            container="{{ data_get($database, 'uuid') }}-proxy" lazy />
                    </x-slot:content>
                    <x-forms.button disabled="{{ !data_get($database, 'is_public') }}" @click="slideOverOpen=true"
                        class="w-28">Proxy Logs</x-forms.button>
                </x-slide-over>
                <x-forms.checkbox instantSave id="database.is_public" label="Make it publicly available" />
            </div>
        </div>
        <x-forms.textarea
            helper="<a target='_blank' class='underline dark:text-white' href='https://raw.githubusercontent.com/Snapchat/KeyDB/unstable/keydb.conf'>KeyDB Default Configuration</a>"
            label="Custom KeyDB Configuration" rows="10" id="database.keydb_conf" />
        <h3 class="pt-4">Advanced</h3>
        <div class="flex flex-col">
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveAdvanced" id="database.is_log_drain_enabled" label="Drain Logs" />
        </div>
    </form>
</div>
