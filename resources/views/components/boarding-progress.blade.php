@props(['currentStep' => 1, 'totalSteps' => 3])

<div class="mx-auto mb-6 w-full max-w-3xl">
    <div
        class="grid grid-cols-3 overflow-hidden rounded-[10px] border border-neutral-200 bg-white dark:border-white/[0.08] dark:bg-white/[0.025]">
        @for ($i = 1; $i <= $totalSteps; $i++)
            <div @class([
                'flex min-h-10 items-center justify-center gap-2 border-r border-neutral-200 px-3 text-[11px] font-medium last:border-r-0 dark:border-white/[0.08]',
                'bg-coollabs/[0.07] text-coollabs dark:bg-warning/[0.09] dark:text-warning' => $i === $currentStep,
                'text-neutral-500 dark:text-fg-dim' => $i !== $currentStep,
            ])>
                <span @class([
                    'flex size-5 items-center justify-center rounded-full border text-[10px] font-semibold',
                    'border-coollabs/25 bg-coollabs/10 dark:border-warning/25 dark:bg-warning/15' => $i === $currentStep,
                    'border-emerald-500/25 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' => $i < $currentStep,
                    'border-neutral-200 dark:border-white/[0.1]' => $i > $currentStep,
                ])>
                    @if ($i < $currentStep)
                        <x-reicon name="check-circle" class="size-3" />
                    @else
                        {{ $i }}
                    @endif
                </span>
                <span class="hidden sm:inline">
                    @if ($i === 1)
                        Server
                    @elseif ($i === 2)
                        Connection
                    @else
                        Complete
                    @endif
                </span>
            </div>
        @endfor
    </div>
</div>
