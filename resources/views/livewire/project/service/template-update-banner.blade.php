<div>
    @if ($this->showBadge)
        <div
            class="mb-4 flex items-center justify-between gap-3 rounded-lg px-4 py-3 text-[13px] ring-1 ring-inset bg-coollabs/[0.06] text-coollabs ring-coollabs/20 dark:bg-warning/[0.06] dark:text-warning dark:ring-warning/20">
            <a href="{{ $href }}" {{ wireNavigate() }}
                class="inline-flex min-w-0 items-center gap-2 hover:underline underline-offset-4">
                <x-reicon name="server-update" class="size-4 shrink-0" />
                <span class="truncate">
                    A newer version of the {{ str($service->service_type)->headline() }} template is available.
                </span>
            </a>
            <div class="flex shrink-0 items-center gap-3">
                <a href="{{ $href }}" {{ wireNavigate() }}
                    class="font-medium underline underline-offset-4">Review changes</a>
                <button type="button" wire:click="dismiss" title="Dismiss until the next version"
                    class="flex size-6 items-center justify-center rounded-md text-coollabs/70 transition-colors hover:bg-coollabs/10 hover:text-coollabs dark:text-warning/70 dark:hover:bg-warning/10 dark:hover:text-warning">
                    <x-reicon name="x" class="size-3.5" />
                    <span class="sr-only">Dismiss update</span>
                </button>
            </div>
        </div>
    @endif
</div>
