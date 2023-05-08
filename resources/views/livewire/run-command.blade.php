<div>
    <form class="flex items-end justify-center gap-2" wire:submit.prevent='runCommand'>
        <x-inputs.input noDirty noLabel autofocus id="command" label="Command" required />
        <select wire:model.defer="server">
            @foreach ($servers as $server)
                <option value="{{ $server->uuid }}">{{ $server->name }}</option>
            @endforeach
        </select>
        <x-inputs.button type="submit">Run</x-inputs.button>
    </form>
    <div class="container w-full pt-10 mx-auto">
        <livewire:activity-monitor />
    </div>
</div>
