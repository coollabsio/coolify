<x-application.settings-section title="Scheduled Tasks" description="Scheduled tasks run in the container of one service of the stack.">
    <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
        @foreach ($services as $service)
            <div wire:key="cluster-stack-tasks-{{ $service['uuid'] }}" class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0">
                    <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ str($service['service'])->headline() }}</h4>
                    <p class="text-xs text-neutral-500 dark:text-fg-dim">{{ trans_choice('{0} No scheduled task|{1} :count scheduled task|[2,*] :count scheduled tasks', $service['scheduledTasks']) }}</p>
                </div>
                <a class="button shrink-0" {{ wireNavigate() }} href="{{ $service['tasksHref'] }}">Manage tasks</a>
            </div>
        @endforeach
    </div>
</x-application.settings-section>
