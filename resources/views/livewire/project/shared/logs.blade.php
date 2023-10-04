<x-layout>
    @if ($type === 'application')
        <h1>Logs</h1>
        <livewire:project.application.heading :application="$resource" />
        <div class="pt-4">
            @if (Str::of($status)->startsWith('running'))
                <livewire:project.shared.get-logs :server="$server" :container="$container" />
            @else
                Application is not running.
            @endif
        </div>
    @elseif ($type === 'database')
        <h1>Logs</h1>
        <livewire:project.database.heading :database="$resource" />
        <div class="pt-4">
            @if (Str::of($status)->startsWith('running'))
                <livewire:project.shared.get-logs :server="$server" :container="$container" />
            @else
                Database is not running.
            @endif
        </div>
    @elseif ($type === 'service')
        <livewire:project.service.navbar :service="$resource" :parameters="$parameters" :query="$query" />
        <div class="flex gap-4 pt-6">
            <div>
                <a class="{{ request()->routeIs('project.service.show') ? 'text-white' : '' }}"
                    href="{{ route('project.service.show', $parameters) }}">
                    <button><- Back</button>
                </a>
            </div>
            <div class="flex-1 pl-8">
                <livewire:project.shared.get-logs :server="$server" :container="$container" />
            </div>
        </div>
    @endif
</x-layout>
