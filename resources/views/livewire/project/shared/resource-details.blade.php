<div class="w-full max-h-[70vh] overflow-y-auto pr-1 -mt-4">
    <div class="pb-4 text-sm dark:text-neutral-400">Identifiers for this resource. Read-only</div>

    <div class="flex flex-col gap-6">
        <div>
            <h3>Resource</h3>
            <div class="pt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                <x-forms.copy-button label="Name" :text="$resource->name ?? ''" />
                <x-forms.copy-button label="UUID" :text="$resource->uuid ?? ''" />
            </div>
        </div>

        @if ($environment_uuid)
            <div>
                <h3>Environment</h3>
                <div class="pt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <x-forms.copy-button label="Name" :text="$environment_name ?? ''" />
                    <x-forms.copy-button label="UUID" :text="$environment_uuid" />
                </div>
            </div>
        @endif

        @if ($project_uuid)
            <div>
                <h3>Project</h3>
                <div class="pt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <x-forms.copy-button label="Name" :text="$project_name ?? ''" />
                    <x-forms.copy-button label="UUID" :text="$project_uuid" />
                </div>
            </div>
        @endif

        @if ($server_uuid)
            <div>
                <h3>Server</h3>
                <div class="pt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <x-forms.copy-button label="Name" :text="$server_name ?? ''" />
                    <x-forms.copy-button label="UUID" :text="$server_uuid" />
                </div>
            </div>
        @endif

        @if (! empty($stack_applications) || ! empty($stack_databases))
            <div>
                <h3>Stack Sub-Resources</h3>
                <div class="pt-2 grid grid-cols-1 gap-3 md:grid-cols-2">
                    @foreach ($stack_applications as $item)
                        <x-forms.copy-button :label="'Application — ' . $item['name']" :text="$item['uuid']" />
                    @endforeach
                    @foreach ($stack_databases as $item)
                        <x-forms.copy-button :label="'Database — ' . $item['name']" :text="$item['uuid']" />
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
