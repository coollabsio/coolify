<div>
    <x-application.settings-section id="flush-cache-section" title="Flush cache"
        helper="Remove every key stored in this cache database.">
        <div
            class="rounded-lg border border-amber-300 bg-amber-50 p-4 ring-1 ring-inset ring-amber-200/60 dark:border-warning/30 dark:bg-warning/[0.08] dark:ring-warning/10">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h4 class="text-sm font-semibold text-amber-700 dark:text-warning">Flush cache</h4>
                        <x-status-badge status="Irreversible" type="warning" />
                    </div>
                    <p class="mt-2 max-w-2xl text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                        Permanently erase every key in
                        <strong class="font-semibold text-black dark:text-fg">{{ $database->name }}</strong>
                        by running <code>FLUSHALL ASYNC</code> inside the running container.
                    </p>
                    <ul class="mt-3 space-y-1 text-xs text-neutral-500 dark:text-fg-dim">
                        <li>• All cached data is removed and cannot be recovered.</li>
                        <li>• The database container keeps running; only its data is cleared.</li>
                        <li>• The database must be running for this action to work.</li>
                    </ul>
                </div>

                <div class="shrink-0">
                    @can('manage', $database)
                        <x-modal-confirmation title="Flush cache?" buttonTitle="Flush cache" submitAction="flush"
                            :confirmWithText="true" confirmationText="{{ $database->name }}"
                            confirmationLabel="Enter the database name to confirm flushing the cache"
                            shortConfirmationLabel="Database name" :confirmWithPassword="false"
                            :actions="[
                                'Every key stored in this cache will be permanently erased.',
                                'This action cannot be undone and data cannot be recovered.',
                            ]" step1ButtonText="Continue" step2ButtonText="Flush cache" />
                    @else
                        <x-forms.button disabled tooltip="You do not have permission to flush this cache.">
                            Flush cache
                        </x-forms.button>
                    @endcan
                </div>
            </div>
        </div>
    </x-application.settings-section>
</div>
