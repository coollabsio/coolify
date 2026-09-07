@props(['step' => 0])

{{--
    Step Mapping (Backend → UI):
    Backend steps 1-2 (config download, env update) → UI Step 1: Preparing
    Backend step 3 (pulling images) → UI Step 2: Helper + UI Step 3: Image
    Backend steps 4-5 (stop/start containers) → UI Step 4: Restart
    Backend step 6 (complete) → mapped in JS mapStepToUI() in upgrade.blade.php

    The currentStep variable is inherited from parent Alpine component (upgradeModal).
--}}
@php
    $steps = [
        1 => 'Preparing',
        2 => 'Helper',
        3 => 'Image',
        4 => 'Restart',
    ];
@endphp

<div class="w-full">
    <div
        class="grid grid-cols-4 overflow-hidden rounded-[10px] border border-neutral-200 bg-white dark:border-white/[0.08] dark:bg-white/[0.025]">
        @foreach ($steps as $stepNumber => $label)
            <div
                class="flex min-h-10 items-center justify-center gap-2 border-r border-neutral-200 px-2 text-[11px] font-medium last:border-r-0 dark:border-white/[0.08] sm:px-3"
                :class="{
                    'bg-neutral-100 text-neutral-900 dark:bg-white/[0.08] dark:text-fg': currentStep === {{ $stepNumber }},
                    'text-emerald-600 dark:text-emerald-400': currentStep > {{ $stepNumber }},
                    'text-neutral-500 dark:text-fg-dim': currentStep < {{ $stepNumber }}
                }">
                <span
                    class="flex size-5 shrink-0 items-center justify-center rounded-full border text-[10px] font-semibold"
                    :class="{
                        'border-neutral-300 bg-white dark:border-white/[0.16] dark:bg-white/[0.1]': currentStep === {{ $stepNumber }},
                        'border-emerald-500/25 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400': currentStep > {{ $stepNumber }},
                        'border-neutral-200 dark:border-white/[0.1]': currentStep < {{ $stepNumber }}
                    }">
                    <template x-if="currentStep > {{ $stepNumber }}">
                        <x-reicon name="check-circle" class="size-3" />
                    </template>
                    <template x-if="currentStep === {{ $stepNumber }}">
                        <svg class="spinner-current size-3 animate-spin text-current" xmlns="http://www.w3.org/2000/svg"
                            fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                    </template>
                    <template x-if="currentStep < {{ $stepNumber }}">
                        <span>{{ $stepNumber }}</span>
                    </template>
                </span>
                <span class="hidden truncate sm:inline">{{ $label }}</span>
            </div>
        @endforeach
    </div>
</div>
