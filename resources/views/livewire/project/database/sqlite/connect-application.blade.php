{{-- Rendered inside the General form, so no <form> here: the button is type=button and Enter is handled explicitly. --}}
<div>
    <x-application.settings-section title="Connect application"
        description="Mount the data volume of this database into an application on the same server. The application must run as user 65532 or as root to access the files.">
        @if (count($applicationOptions) === 0)
            <p class="text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                No applications are deployed on this server yet.
            </p>
        @else
            <div class="flex flex-col gap-4">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.searchable-listbox id="applicationUuid" label="Application" required
                        :options="$applicationOptions" placeholder="Select an application"
                        searchPlaceholder="Search applications…" emptyText="No matching applications"
                        helper="Docker Compose applications are not supported because Coolify renames named volumes in compose files." />
                    <x-forms.input id="mountPath" label="Mount path" required placeholder="/var/lib/sqlite"
                        canGate="update" :canResource="$database" x-on:keydown.enter.prevent="$wire.connect()"
                        helper="Any directory inside the application container. The database files ({{ str($database->sqlite_databases)->replace(',', ', ') }}) will be available there." />
                </div>

                <div class="flex justify-end">
                    <x-forms.button type="button" wire:click="connect" canGate="update" :canResource="$database">
                        Mount volume
                    </x-forms.button>
                </div>
            </div>
        @endif
    </x-application.settings-section>
</div>
