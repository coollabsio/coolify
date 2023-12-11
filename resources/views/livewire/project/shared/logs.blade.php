<div>
    @if ($type === 'application')
        <h1>Logs</h1>
        <livewire:project.application.heading :application="$resource" />
        <div class="pt-4">
            @forelse ($containers as $container)
                @if ($loop->first)
                    <h2 class="pb-4">Logs</h2>
                @endif
                <livewire:project.shared.get-logs :server="$server" :resource="$resource" :container="$container" />
            @empty
                <div>No containers are not running.</div>
            @endforelse
        </div>
    @elseif ($type === 'database')
        <h1>Logs</h1>
        <livewire:project.database.heading :database="$resource" />
        <div class="pt-4">
            @forelse ($containers as $container)
                @if ($loop->first)
                    <h2 class="pb-4">Logs</h2>
                @endif
                <livewire:project.shared.get-logs :server="$server" :resource="$resource" :container="$container" />
            @empty
                <div>No containers are not running.</div>
            @endforelse
        </div>
    @elseif ($type === 'service')
        <div>
            @forelse ($containers as $container)
                @if ($loop->first)
                    <h2 class="pb-4">Logs</h2>
                @endif
                <livewire:project.shared.get-logs :server="$server" :resource="$resource" :container="$container" />
            @empty
                <div>No containers are not running.</div>
            @endforelse
        </div>
    @endif
</div>
