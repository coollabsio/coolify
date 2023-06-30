<div class="pt-4">
    <livewire:project.application.deployment-navbar :application_deployment_queue="$application_deployment_queue" />
    @if (data_get($application_deployment_queue, 'status') === 'in_progress')
        <div class="flex items-center gap-1 pt-2 ">Deployment is
            <div class="text-warning"> {{ Str::headline(data_get($this->application_deployment_queue, 'status')) }}.
            </div>
            <x-loading class="loading-ring" />
        </div>
        <div class="">Logs will be updated automatically.</div>
    @else
        <div class="pt-2 ">Deployment is <span
                class="text-warning">{{ Str::headline(data_get($application_deployment_queue, 'status')) }}</span>.
        </div>
    @endif
    <div @if ($isKeepAliveOn) wire:poll.2000ms="polling" @endif
        class="scrollbar flex flex-col-reverse w-full overflow-y-auto border border-dotted rounded border-coolgray-400 max-h-[32rem] p-2 px-4 mt-4 text-xs">
        <span class="flex flex-col">
            @if (decode_remote_command_output($application_deployment_queue)->count() > 0)
                @foreach (decode_remote_command_output($application_deployment_queue) as $line)
                    <div @class([
                        'font-mono break-all whitespace-pre-wrap',
                        'text-neutral-400' => $line['type'] == 'stdout',
                        'text-error' => $line['type'] == 'stderr',
                        'text-warning' => $line['hidden'],
                    ])>[{{ $line['timestamp'] }}] @if ($line['hidden'])<br>Command: {{ $line['command'] }} <br>Output: @endif{{ $line['output'] }}@if ($line['hidden']) @endif</div>
                @endforeach
            @else
                <span class="font-mono text-neutral-400">No logs yet.</span>
            @endif
        </span>
    </div>
</div>
