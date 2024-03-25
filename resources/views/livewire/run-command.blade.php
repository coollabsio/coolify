<div>
    <form class="flex flex-col justify-center gap-2 xl:items-end xl:flex-row" wire:submit='runCommand'>
        <x-forms.input placeholder="ls -l" autofocus id="command" label="Command" required />
        <x-forms.select label="Server" id="server" required>
            @foreach ($servers as $server)
                @if ($loop->first)
                    <option selected value="{{ $server->uuid }}">{{ $server->name }}</option>
                @else
                    <option value="{{ $server->uuid }}">{{ $server->name }}</option>
                @endif
            @endforeach
        </x-forms.select>
        <x-forms.button type="submit">Execute Command
        </x-forms.button>
    </form>
    <div class="w-full pt-10 mx-auto">
        <livewire:activity-monitor header="Command output" />
    </div>
</div>
