<div>
    @if ($type === 'application')
        <h1>Execute Command</h1>
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'database')
        <h1>Execute Command</h1>
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <h2>Execute Command</h2>
    @endif
    @if (count($containers) > 0)
        <form class="flex flex-col gap-2 pt-4" wire:submit.prevent='runCommand'>
            <div class="flex gap-2">
                <x-forms.input placeholder="ls -l" autofocus id="command" label="Command" required />
                <x-forms.input id="workDir" label="Working directory" />
            </div>
            <x-forms.select label="Container" id="container" required>
                <option disabled selected>Select container</option>
                @foreach ($containers as $container)
                    <option value="{{ $container }}">{{ $container }}</option>
                @endforeach
            </x-forms.select>
            <x-forms.button type="submit">Run</x-forms.button>
        </form>
    @else
        <div class="pt-4">No containers are not running.</div>
    @endif
    <div class="container w-full pt-10 mx-auto">
        <livewire:activity-monitor header="Command output" />
    </div>
</div>
