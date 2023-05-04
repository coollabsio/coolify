<div>
    <form class="flex gap-2" wire:submit.prevent='runCommand'>
        <x-inputs.input autofocus id="command" label="Command" required />
        <select wire:model.defer="server">
            @foreach ($servers as $server)
                <option value="{{ $server->uuid }}">{{ $server->name }}</option>
            @endforeach
        </select>
        <x-inputs.button type="submit">Run</x-inputs.button>
    </form>
    <livewire:activity-monitor />
</div>
