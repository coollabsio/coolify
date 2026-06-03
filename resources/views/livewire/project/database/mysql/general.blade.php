<div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            <x-modal-input title="Resource Details" buttonTitle="Details">
                <livewire:project.shared.resource-details :resource="$database" />
            </x-modal-input>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="name" canGate="update" :canResource="$database" />
            <x-forms.input label="Description" id="description" canGate="update" :canResource="$database" />
            <x-forms.input label="Image" id="image" required
                helper="For all available images, check here:<br><br><a target='_blank' href='https://hub.docker.com/_/mysql'>https://hub.docker.com/_/mysql</a>" canGate="update" :canResource="$database" />
        </div>
        <div class="pt-2 dark:text-warning">If you change the values in the database, please sync it here, otherwise
            automations (like backups) won't work.
        </div>
        @if ($database->started_at)
            <div class="flex xl:flex-row flex-col gap-2">
                <x-forms.input label="Root Password" id="mysqlRootPassword" type="password" required
                    helper="If you change this in the database, please sync it here, otherwise automations (like backups) won't work." canGate="update" :canResource="$database" />
                <x-forms.input label="Normal User" id="mysqlUser" required
                    helper="If you change this in the database, please sync it here, otherwise automations (like backups) won't work." canGate="update" :canResource="$database" />
                <x-forms.input label="Normal User Password" id="mysqlPassword" type="password" required
                    helper="If you change this in the database, please sync it here, otherwise automations (like backups) won't work." canGate="update" :canResource="$database" />
            </div>
            <div class="flex flex-col gap-2">
                <x-forms.input label="Initial Database" id="mysqlDatabase"
                    placeholder="If empty, it will be the same as Username." readonly
                    helper="You can only change this in the database." canGate="update" :canResource="$database" />
            </div>
        @else
            <div class="flex xl:flex-row flex-col gap-4 pb-2">
                <x-forms.input label="Root Password" id="mysqlRootPassword" type="password"
                    helper="You can only change this in the database." canGate="update" :canResource="$database" />
                <x-forms.input label="Normal User" id="mysqlUser" required
                    helper="You can only change this in the database." canGate="update" :canResource="$database" />
                <x-forms.input label="Normal User Password" id="mysqlPassword" type="password" required
                    helper="You can only change this in the database." canGate="update" :canResource="$database" />
            </div>
            <div class="flex flex-col gap-2">
                <x-forms.input label="Initial Database" id="mysqlDatabase"
                    placeholder="If empty, it will be the same as Username."
                    helper="You can only change this in the database." canGate="update" :canResource="$database" />
            </div>
        @endif
        <div class="pt-2">
            <x-forms.input
                helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' target='_blank' href='https://coolify.io/docs/knowledge-base/docker/custom-commands'>docs.</a>"
                placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k"
                id="customDockerRunOptions" label="Custom Docker Options" canGate="update" :canResource="$database" />
        </div>
        <div class="flex flex-col gap-2">
            <h3 class="py-2">Network</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="3000:5432" id="portsMappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system.<br><span class='inline-block font-bold dark:text-warning'>Example</span>3000:5432,3002:5433" canGate="update" :canResource="$database" />
            </div>
        </div>

        <livewire:project.database.mysql.status-info :database="$database" />

        <div>
            <div class="flex flex-col py-2 w-64">
                <div class="flex items-center gap-2 pb-2">
                    <div class="flex items-center">
                        <h3>Proxy</h3>
                        <x-loading wire:loading wire:target="instantSave" />
                    </div>
                    @if (data_get($database, 'is_public'))
                        <x-slide-over fullScreen>
                            <x-slot:title>Proxy Logs</x-slot:title>
                            <x-slot:content>
                                <livewire:project.shared.get-logs :server="$server" :resource="$database"
                                    container="{{ data_get($database, 'uuid') }}-proxy" :collapsible="false" lazy />
                            </x-slot:content>
                            <x-forms.button disabled="{{ !data_get($database, 'is_public') }}"
                                @click="slideOverOpen=true">Logs</x-forms.button>
                        </x-slide-over>
                    @endif
                </div>
                <x-forms.checkbox instantSave id="isPublic" label="Make it publicly available" canGate="update" :canResource="$database" />
            </div>
            <div class="flex flex-col gap-2">
            <x-forms.input type="number" placeholder="5432" disabled="{{ $isPublic }}"
                id="publicPort" label="Public Port" canGate="update" :canResource="$database" />
            <x-forms.input type="number" placeholder="3600" disabled="{{ $isPublic }}" id="publicPortTimeout"
                label="Proxy Timeout (seconds)" helper="Timeout for the public TCP proxy connection in seconds. Default: 3600 (1 hour)." canGate="update" :canResource="$database" />
            </div>
        </div>
        <x-forms.textarea label="Custom Mysql Configuration" rows="10" id="mysqlConf" canGate="update" :canResource="$database" />
        <h3 class="pt-4">Advanced</h3>
        <div class="flex flex-col">
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveAdvanced" id="isLogDrainEnabled" label="Drain Logs" canGate="update" :canResource="$database" />
        </div>
    </form>
</div>
