<div>
    <form wire:submit="submit" class="flex flex-col gap-2">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit" canGate="update" :canResource="$database">
                Save
            </x-forms.button>
        </div>
        <div class="flex flex-wrap gap-2 sm:flex-nowrap">
            <x-forms.input label="Name" id="name" canGate="update" :canResource="$database" />
            <x-forms.input label="Description" id="description" canGate="update" :canResource="$database" />
            <x-forms.input label="Image" id="image" required canGate="update" :canResource="$database"
                helper="For all available images, check here:<br><br><a target='_blank' href='https://hub.docker.com/r/surrealdb/surrealdb'>https://hub.docker.com/r/surrealdb/surrealdb</a>" />
        </div>
        <div class="pt-2 dark:text-warning">If you change the values in the database, please sync it here, otherwise
            automations (like backups) won't work.
        </div>
        <div class="flex xl:flex-row flex-col gap-2 pb-2">
            <x-forms.input label="Username" id="surrealdbUser" placeholder="If empty: root" canGate="update"
                :canResource="$database" />
            <x-forms.input label="Password" id="surrealdbPassword" type="password" required canGate="update"
                :canResource="$database" />
        </div>
        <div class="flex flex-col gap-2">
            <div class="w-64">
                <x-forms.checkbox id="useTikv" label="Use TiKV" wire:model.live="useTikv"
                    helper="Use TiKV as the storage engine for SurrealDB." canGate="update" :canResource="$database" />
            </div>
            @if ($useTikv)
                <x-forms.input label="TiKV Endpoint" id="tikvEndpoint" placeholder="pd:2379" canGate="update"
                    :canResource="$database" helper="The endpoint of the TiKV PD server." />
            @endif
        </div>
        <x-forms.input
            helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' {{ wireNavigate() }} href='https://coolify.io/docs/knowledge-base/docker/custom-commands'>docs.</a>"
            placeholder="--cap-add SYS_ADMIN"
            id="customDockerRunOptions" label="Custom Docker Options" canGate="update" :canResource="$database" />
        <div class="flex flex-col gap-2">
            <h3 class="py-2">Network</h3>
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="8000:8000" id="portsMappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system."
                    canGate="update" :canResource="$database" />
            </div>

            <x-forms.input label="SurrealDB URL (internal)"
                type="password" readonly wire:model="db_url" />
            @if ($db_url_public)
                <x-forms.input label="SurrealDB URL (public)"
                    type="password" readonly wire:model="db_url_public" />
            @endif
        </div>

        <div class="flex flex-col gap-2">
            <div class="flex items-center gap-2 py-2">
                <h3>Proxy</h3>
                <x-loading wire:loading wire:target="instantSave" />
            </div>
            <div class="flex flex-col gap-2 w-64">
                <x-forms.checkbox instantSave id="isPublic" label="Make it publicly available"
                    canGate="update" :canResource="$database" />
                </div>
                <x-forms.input placeholder="600s" id="publicProxyTimeout"
                    label="Proxy Timeout"
                    helper="Timeout for the public proxy. Default is 600s (10 minutes). A value of 0 disables the timeout. <br><br>Examples: 600s, 10m, 1h, 0"
                    canGate="update" :canResource="$database" />
            </div>
            <x-forms.input placeholder="8000" disabled="{{ $isPublic }}" id="publicPort"
                label="Public Port" canGate="update" :canResource="$database" />
        </div>
    </form>

    <div class="flex flex-col gap-4 pt-4">
        <h3>Advanced</h3>
        <div class="flex flex-col">
            <x-forms.checkbox helper="Drain logs to your configured log drain endpoint in your Server settings."
                instantSave="instantSaveAdvanced" id="isLogDrainEnabled" label="Drain Logs" canGate="update"
                :canResource="$database" />
        </div>
    </div>
</div>
