<div>
    <livewire:project.application.deployment-navbar :activity="$activity" :application="$application" :deployment_uuid="$deployment_uuid" />
    <h3 class="pb-0">Logs</h3>
    @if (data_get($activity, 'properties.status') === 'in_progress')
        <div class="pt-2 text-sm">Deployment is
            <span class="text-warning">{{ Str::headline(data_get($activity, 'properties.status')) }}</span>. Logs will
            be updated
            automatically.
        </div>
    @else
        <div class="pt-2 text-sm">Deployment is <span
                class="text-warning">{{ Str::headline(data_get($activity, 'properties.status')) }}</span>.</div>
    @endif
    <div
        class="scrollbar flex flex-col-reverse w-full overflow-y-auto border border-solid rounded border-coolgray-300 max-h-[32rem] p-4 mt-4 text-xs text-white">
        <pre class="font-mono whitespace-pre-wrap" @if ($isKeepAliveOn) wire:poll.2000ms="polling" @endif>{{ \App\Actions\CoolifyTask\RunRemoteProcess::decodeOutput($activity) }}</pre>
    </div>
</div>
