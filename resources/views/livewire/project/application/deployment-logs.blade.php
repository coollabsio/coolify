<div class="pt-4">
    <livewire:project.application.deployment-navbar :activity="$activity" :application="$application" :deployment_uuid="$deployment_uuid" />
    @if (data_get($activity, 'properties.status') === 'in_progress')
        <div class="flex items-center gap-1 pt-2 text-sm">Deployment is
            <div class="text-warning"> {{ Str::headline(data_get($activity, 'properties.status')) }}.</div>
            <x-loading class="loading-ring" />
        </div>
        <div class="text-sm">Logs will be updated automatically.</div>
    @else
        <div class="pt-2 text-sm">Deployment is <span
                class="text-warning">{{ Str::headline(data_get($activity, 'properties.status')) }}</span>.
        </div>
    @endif
    <div
        class="scrollbar flex flex-col-reverse w-full overflow-y-auto border border-solid rounded border-coolgray-300 max-h-[32rem] p-4 mt-4 text-xs text-white">
        <pre class="font-mono whitespace-pre-wrap" @if ($isKeepAliveOn) wire:poll.2000ms="polling" @endif>{{ \App\Actions\CoolifyTask\RunRemoteProcess::decodeOutput($activity) }}</pre>
    </div>
</div>
