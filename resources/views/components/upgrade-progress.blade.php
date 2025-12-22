@props(['step' => 0])

{{--
    Step Mapping (Backend → UI):
    Backend steps 1-2 (config download, env update) → UI Step 1: Preparing
    Backend step 3 (pulling images) → UI Step 2: Helper + UI Step 3: Image
    Backend steps 4-5 (stop/start containers) → UI Step 4: Restart
    Backend step 6 (complete) → mapped in JS mapStepToUI() in upgrade.blade.php

    The currentStep variable is inherited from parent Alpine component (upgradeModal).
--}}
<div class="w-full" x-data="{ activeStep: {{ $step }} }" x-effect="activeStep = $el.closest('[x-data]')?.__x?.$data?.currentStep ?? {{ $step }}">
    <div class="flex items-center justify-between">
        {{-- Step 1: Preparing --}}
        <div class="flex items-center flex-1">
            <div class="flex flex-col items-center">
                <div class="flex items-center justify-center size-8 rounded-full border-2 transition-all duration-300"
                    :class="{
                        'bg-success border-success': currentStep > 1,
                        'bg-warning/20 border-warning': currentStep === 1,
                        'border-neutral-400 dark:border-coolgray-300': currentStep < 1
                    }">
                    <template x-if="currentStep > 1">
                        <svg class="size-4 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </template>
                    <template x-if="currentStep === 1">
                        <svg class="size-4 text-warning animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </template>
                    <template x-if="currentStep < 1">
                        <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">1</span>
                    </template>
                </div>
                <span class="mt-1.5 text-xs font-medium transition-colors duration-300"
                    :class="{
                        'text-success': currentStep > 1,
                        'text-warning': currentStep === 1,
                        'text-neutral-500 dark:text-neutral-400': currentStep < 1
                    }">Preparing</span>
            </div>
            <div class="flex-1 h-0.5 mx-2 transition-all duration-300"
                :class="currentStep > 1 ? 'bg-success' : 'bg-neutral-300 dark:bg-coolgray-300'"></div>
        </div>

        {{-- Step 2: Helper --}}
        <div class="flex items-center flex-1">
            <div class="flex flex-col items-center">
                <div class="flex items-center justify-center size-8 rounded-full border-2 transition-all duration-300"
                    :class="{
                        'bg-success border-success': currentStep > 2,
                        'bg-warning/20 border-warning': currentStep === 2,
                        'border-neutral-400 dark:border-coolgray-300': currentStep < 2
                    }">
                    <template x-if="currentStep > 2">
                        <svg class="size-4 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </template>
                    <template x-if="currentStep === 2">
                        <svg class="size-4 text-warning animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </template>
                    <template x-if="currentStep < 2">
                        <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">2</span>
                    </template>
                </div>
                <span class="mt-1.5 text-xs font-medium transition-colors duration-300"
                    :class="{
                        'text-success': currentStep > 2,
                        'text-warning': currentStep === 2,
                        'text-neutral-500 dark:text-neutral-400': currentStep < 2
                    }">Helper</span>
            </div>
            <div class="flex-1 h-0.5 mx-2 transition-all duration-300"
                :class="currentStep > 2 ? 'bg-success' : 'bg-neutral-300 dark:bg-coolgray-300'"></div>
        </div>

        {{-- Step 3: Image --}}
        <div class="flex items-center flex-1">
            <div class="flex flex-col items-center">
                <div class="flex items-center justify-center size-8 rounded-full border-2 transition-all duration-300"
                    :class="{
                        'bg-success border-success': currentStep > 3,
                        'bg-warning/20 border-warning': currentStep === 3,
                        'border-neutral-400 dark:border-coolgray-300': currentStep < 3
                    }">
                    <template x-if="currentStep > 3">
                        <svg class="size-4 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </template>
                    <template x-if="currentStep === 3">
                        <svg class="size-4 text-warning animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </template>
                    <template x-if="currentStep < 3">
                        <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">3</span>
                    </template>
                </div>
                <span class="mt-1.5 text-xs font-medium transition-colors duration-300"
                    :class="{
                        'text-success': currentStep > 3,
                        'text-warning': currentStep === 3,
                        'text-neutral-500 dark:text-neutral-400': currentStep < 3
                    }">Image</span>
            </div>
            <div class="flex-1 h-0.5 mx-2 transition-all duration-300"
                :class="currentStep > 3 ? 'bg-success' : 'bg-neutral-300 dark:bg-coolgray-300'"></div>
        </div>

        {{-- Step 4: Restart --}}
        <div class="flex items-center">
            <div class="flex flex-col items-center">
                <div class="flex items-center justify-center size-8 rounded-full border-2 transition-all duration-300"
                    :class="{
                        'bg-success border-success': currentStep > 4,
                        'bg-warning/20 border-warning': currentStep === 4,
                        'border-neutral-400 dark:border-coolgray-300': currentStep < 4
                    }">
                    <template x-if="currentStep > 4">
                        <svg class="size-4 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                    </template>
                    <template x-if="currentStep === 4">
                        <svg class="size-4 text-warning animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </template>
                    <template x-if="currentStep < 4">
                        <span class="text-xs font-medium text-neutral-500 dark:text-neutral-400">4</span>
                    </template>
                </div>
                <span class="mt-1.5 text-xs font-medium transition-colors duration-300"
                    :class="{
                        'text-success': currentStep > 4,
                        'text-warning': currentStep === 4,
                        'text-neutral-500 dark:text-neutral-400': currentStep < 4
                    }">Restart</span>
            </div>
        </div>
    </div>
</div>
