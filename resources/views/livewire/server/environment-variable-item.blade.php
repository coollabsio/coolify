<form wire:submit='submit' class="flex flex-col gap-2 p-4 rounded dark:bg-coolgray-100">
    <div class="flex flex-wrap gap-2 sm:flex-nowrap items-end">
        <x-forms.input id="key" label="Key" required canGate="update" :canResource="$server" />
        @if ($isLocked)
            <x-forms.input id="value" label="Value" type="password" disabled
                placeholder="(Locked — delete and re-add to change)" />
        @else
            <x-forms.input id="value" label="Value" type="password" canGate="update" :canResource="$server" />
        @endif
    </div>
    <div class="flex gap-2">
        @can('update', $server)
            <x-forms.button type="submit">Save</x-forms.button>
            @if (! $isLocked)
                <x-forms.button wire:click='lock' isWarning>Lock</x-forms.button>
            @endif
            <x-modal-confirmation title="Confirm Deletion?"
                buttonTitle="Delete"
                isErrorButton
                action="delete"
                :content="'Are you sure you want to delete the environment variable <strong>' . e($key) . '</strong>?'" />
        @endcan
    </div>
</form>
