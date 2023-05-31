<div>
    <div class="pt-2 text-sm">Build status: {{ data_get($activity, 'properties.status') }}</div>
    @if (data_get($activity, 'properties.status') === 'in_progress')
        <livewire:project.application.deployment-navbar :activity="$activity" :application="$application" :deployment_uuid="$deployment_uuid" />
    @endif
    <div
        class="scrollbar flex flex-col-reverse w-full overflow-y-auto border border-solid rounded border-coolgray-300 max-h-[32rem] p-4 mt-4 text-xs text-white">
        <pre class="font-mono whitespace-pre-wrap" @if ($isKeepAliveOn) wire:poll.2000ms="polling" @endif>{{ \App\Actions\CoolifyTask\RunRemoteProcess::decodeOutput($activity) }}</pre>
    </div>
</div>
