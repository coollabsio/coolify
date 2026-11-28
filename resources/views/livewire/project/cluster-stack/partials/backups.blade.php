<x-application.settings-section title="Backups" description="Volume backups, and dump backups of databases, run for each service of the stack.">
    <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
        @foreach ($services as $service)
            <div wire:key="cluster-stack-backups-{{ $service['uuid'] }}" class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                        <x-reicon :name="$service['isDatabase'] ? 'database' : 'grid'" class="size-4" />
                    </div>
                    <div class="min-w-0">
                        <h4 class="truncate text-sm font-semibold text-black dark:text-fg">{{ str($service['service'])->headline() }}</h4>
                        <p class="text-xs text-neutral-500 dark:text-fg-dim">{{ trans_choice('{0} No backup schedule|{1} :count backup schedule|[2,*] :count backup schedules', $service['backupSchedules']) }}</p>
                    </div>
                </div>
                <a class="button shrink-0" {{ wireNavigate() }} href="{{ $service['backupsHref'] }}">Manage backups</a>
            </div>
        @endforeach
    </div>
</x-application.settings-section>
