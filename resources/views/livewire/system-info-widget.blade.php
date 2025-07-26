<div class="grid grid-cols-1 xl:grid-cols-1 gap-4">
    <div wire:poll.30s class="flex flex-wrap text-sm gap-x-6 gap-y-2">
        {{-- System --}}
        <div><strong>OS:</strong> {{ $info['os'] }}</div>
        <div><strong>Hostname:</strong> {{ $info['hostname'] }}</div>
        <div><strong>Public IP:</strong> {{ $info['public_ip'] }}</div>
        <div><strong>CPU:</strong> {{ $info['cpu_cores'] }}(cores) {{ $info['cpu_model'] }}</div>
        <div><strong>Now:</strong> {{ $info['datetime'] }}</div>
        <div><strong>Uptime:</strong> <span class="animate-pulse">{{ $info['uptime'] }}</span></div>

        {{-- Memory --}}
        <div>
            <strong>Memory:</strong> <span class="animate-pulse">{{ $info['memory']['used'] }} / {{ $info['memory']['total'] }} GB</span>
        </div>
        <div><strong>Swap:</strong> <span class="animate-pulse">{{ $info['swap_usage'] }}</span></div>

        {{-- System Load --}}
        <div><strong>Load Avg:</strong> <span class="animate-pulse">{{ $info['load_average'] }}</span></div>
        <div><strong>Disk Usage:</strong> <span class="animate-pulse">{{ $info['disk_usage'] }}</span></div>

        {{-- Docker --}}
        @if ($info['containers']['running'] > 0)
            <div>
                <strong>Containers:</strong>
                {{ $info['containers']['running'] }}/{{ $info['containers']['total'] }}
            </div>
        @endif
        <div>
            <button wire:click="$refresh" title="Manual refresh (auto every 30s)"
                class="text-xs text-coollabs hover:text-coollabs-100 hover:scale-110 transition cursor-pointer">
                ↻
            </button>
        </div>
    </div>

</div>

