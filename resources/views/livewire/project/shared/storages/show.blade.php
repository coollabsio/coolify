<div>
    <x-modal yesOrNo modalId="{{ $modalId }}" modalTitle="Delete Storage">
        <x-slot:modalBody>
            <p>This storage will be deleted <span class="font-bold text-warning">({{ $storage->name }})</span>. It is
                not
                reversible. <br>Please think again.</p>
        </x-slot:modalBody>
    </x-modal>
    @if ($isReadOnly)
        <span class="text-warning">Please modify storage layout in your <a
                class="underline" href="{{ Str::of(url()->current())->beforeLast('/') }}#compose">Docker Compose</a> file.</span>
    @endif
    <form wire:submit.prevent='submit' class="flex flex-col gap-2 pt-4 xl:items-end xl:flex-row">
        @if ($isReadOnly)
            <x-forms.input id="storage.name" label="Name" required readonly />
            <x-forms.input id="storage.host_path" label="Source Path" readonly />
            <x-forms.input id="storage.mount_path" label="Destination Path" required readonly />
        @else
            <x-forms.input id="storage.name" label="Name" required />
            <x-forms.input id="storage.host_path" label="Source Path" />
            <x-forms.input id="storage.mount_path" label="Destination Path" required />
            <div class="flex gap-2">
                <x-forms.button type="submit">
                    Update
                </x-forms.button>
                <x-forms.button isError isModal modalId="{{ $modalId }}">
                    Delete
                </x-forms.button>
            </div>
        @endif
    </form>
</div>
