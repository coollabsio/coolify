<div>
    <form class="flex flex-col gap-2 pb-6" wire:submit='submit'>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$storage" label="Name" id="name" />
            <x-forms.input canGate="update" :canResource="$storage" label="Description" id="description" />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$storage" required label="Endpoint" id="endpoint" />
            <x-forms.input canGate="update" :canResource="$storage" required label="Bucket" id="bucket" />
            <x-forms.input canGate="update" :canResource="$storage" required label="Region" id="region" />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$storage" label="Path Prefix" id="path"
                placeholder="e.g., production or instance-1"
                helper="Optional prefix added before the standard backup path (/data/coolify/backups/...). Useful for separating multiple Coolify instances in a single bucket." />
        </div>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$storage" required type="password" label="Access Key"
                id="key" />
            <x-forms.input canGate="update" :canResource="$storage" required type="password" label="Secret Key"
                id="secret" />
        </div>
        @can('validateConnection', $storage)
            <x-forms.button class="mt-4" isHighlighted wire:click="testConnection">
                Validate Connection
            </x-forms.button>
        @endcan
    </form>
</div>
