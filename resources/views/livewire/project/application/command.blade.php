<div>
    @if ($type === 'application')
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'database')
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.service.navbar :service="$resource" :parameters="$parameters" :query="$query" />
        <div class="pt-5 pb-5">
            <a class="{{ request()->routeIs('project.service.show') ? 'text-white' : '' }}"
                href="{{ route('project.service.show', $parameters) }}">
                <button><- Back</button>
            </a>
        </div>
    @endif
    <form class="flex flex-col justify-center gap-2 xl:items-end xl:flex-row" wire:submit.prevent='runCommand'>
        <x-forms.input placeholder="ls -l" autofocus id="command" label="Command" required />
        <x-forms.input id="dir" label="Working directory" />
        <x-forms.select label="Container" id="container" required>
            <option selected>Select container</option>
            @foreach ($containers as $container)
                <option value="{{ $container }}">{{ $container }}</option>
            @endforeach
        </x-forms.select>
        <x-forms.button type="submit">Execute Command
        </x-forms.button>
    </form>
    <div class="container w-full pt-10 mx-auto">
        <livewire:activity-monitor header="Command output" />
    </div>
</div>