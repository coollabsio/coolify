@props(['server'])

@php($gpus = $server->detectedGpus())

<div {{ $attributes->merge(['class' => 'text-[12px] leading-5 text-neutral-600 dark:text-fg-dim']) }}>
    @if ($gpus === null)
        <p>Not checked yet. Refresh to find GPUs.</p>
    @elseif ($gpus === [])
        <p>No NVIDIA GPU found (<span class="font-mono">nvidia-smi</span> is not installed or shows no GPU).</p>
    @else
        <ul>
            @foreach ($gpus as $gpu)
                <li wire:key="server-{{ $server->id }}-gpu-{{ $gpu['uuid'] }}">
                    <span class="font-mono font-medium text-neutral-950 dark:text-fg">{{ $gpu['index'] }}</span>
                    · {{ $gpu['name'] }} · {{ round($gpu['memory_mb'] / 1024, 1) }} GB ·
                    <span class="font-mono">{{ $gpu['uuid'] }}</span>
                </li>
            @endforeach
        </ul>
        @unless (data_get($server->server_metadata, 'nvidia_container_toolkit'))
            <p class="mt-1 text-warning-900 dark:text-warning">
                NVIDIA Container Toolkit not found. Containers cannot use these GPUs until you
                <a href="https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/install-guide.html"
                    class="underline" target="_blank">install it</a> and restart Docker.
            </p>
        @endunless
    @endif
</div>
