<div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit" canGate="update" :canResource="$database">
                Save
            </x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="name" canGate="update" :canResource="$database" />
            <x-forms.input label="Description" id="description" canGate="update" :canResource="$database" />
            <x-forms.input label="Image" id="image" required canGate="update" :canResource="$database"
                helper="For all available images, check here:<br><br><a target='_blank' href='https://hub.docker.com/r/surrealdb/surrealdb/'>https://hub.docker.com/r/surrealdb/surrealdb/</a>" />
        </div>

        <div class="flex gap-2">
            <x-forms.select label="Storage Backend" id="storageBackend" required canGate="update" :canResource="$database">
                <option value="surrealkv">SurrealKV (Default)</option>
                <option value="memory">Memory (Ephemeral)</option>
                <option value="file">File (Persisted)</option>
                <option value="tikv">TiKV (Distributed)</option>
                <option value="rocksdb">RocksDB (Experimental)</option>
            </x-forms.select>
            @if ($storageBackend === 'tikv')
                <x-forms.input label="TiKV Endpoint" id="tikvEndpoint" placeholder="127.0.0.1:2379" required canGate="update" :canResource="$database" />
            @endif
        </div>

        <div class="flex gap-2">
            <x-forms.select label="Authentication Mode" id="surrealAuth" required canGate="update" :canResource="$database">
                <option value="unauthenticated">Unauthenticated</option>
                <option value="root">Root Authentication</option>
            </x-forms.select>
        </div>

        @if ($database->started_at)
            <div class="flex gap-2">
                <x-forms.input label="Initial Username" id="surrealUser" placeholder="If empty: root"
                    readonly helper="You can only change this in the database." canGate="update" :canResource="$database" />
                <x-forms.input label="Initial Password" id="surrealPassword" type="password" required readonly
                    helper="You can only change this in the database." canGate="update" :canResource="$database" />
            </div>
        @else
            <div class=" dark:text-warning">Please verify these values. You can only modify them before the initial
                start. After that, you need to modify it in the database.
            </div>
            <div class="flex gap-2">
                <x-forms.input label="Username" id="surrealUser" required canGate="update" :canResource="$database" />
                <x-forms.input label="Password" id="surrealPassword" type="password" required canGate="update"
                    :canResource="$database" />
            </div>
        @endif
        <x-forms.input
            helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/custom-commands'>docs.</a>"
            placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k"
            id="customDockerRunOptions" label="Custom Docker Options" canGate="update" :canResource="$database" />
        <div class="flex flex-col gap-2">
            <h3 class="py-2">Network</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="8000:8000" id="portsMappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system.<br><span class='inline-block font-bold dark:text-warning'>Example</span>8000:8000"
                    canGate="update" :canResource="$database" />
            </div>
            <x-forms.input label="SurrealDB URL (internal)"
                helper="Internal URL reachable by other services in the same network."
                readonly wire:model="dbUrl" canGate="update" :canResource="$database" />
            @if ($dbUrlPublic)
                <x-forms.input label="SurrealDB URL (public)"
                    helper="Publicly reachable URL."
                    readonly wire:model="dbUrlPublic" canGate="update" :canResource="$database" />
            @else
                <x-forms.input label="SurrealDB URL (public)"
                    readonly value="Starting the database will generate this if Publicly Available is checked." canGate="update" :canResource="$database" />
            @endif
        </div>
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
                                    container="{{ data_get($database, 'uuid') }}-proxy" :collapsible="false" lazy />
                            </x-slot:content>
                            <x-forms.button disabled="{{ !$isPublic }}"
                                @click="slideOverOpen=true">Logs</x-forms.button>
                        </x-slide-over>
                    @endif
                </div>
                <x-forms.checkbox instantSave id="isPublic" label="Make it publicly available" canGate="update"
                    :canResource="$database" />
            </div>
            <div class="flex flex-col gap-2">
            <x-forms.input type="number" placeholder="8000" disabled="{{ $isPublic }}" id="publicPort" label="Public Port"
                canGate="update" :canResource="$database" />
            <x-forms.input type="number" placeholder="3600" disabled="{{ $isPublic }}" id="publicPortTimeout"
                label="Proxy Timeout (seconds)" helper="Timeout for the public TCP proxy connection in seconds. Default: 3600 (1 hour)." canGate="update" :canResource="$database" />
            </div>
    </form>
    <h3 class="pt-4">Advanced</h3>
    <div class="w-64">
        <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
            instantSave="instantSaveAdvanced" id="isLogDrainEnabled" label="Drain Logs" canGate="update"
            :canResource="$database" />
    </div>
</div>
