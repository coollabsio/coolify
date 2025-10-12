@props(['currentStep' => 1, 'totalSteps' => 3])

<div class="w-full max-w-2xl mx-auto mb-8">
    <div class="flex items-center justify-between">
        @for ($i = 1; $i <= $totalSteps; $i++)
            <div class="flex items-center {{ $i < $totalSteps ? 'flex-1' : '' }}">
                <div class="flex flex-col items-center">
                    <div
                        class="flex items-center justify-center size-10 rounded-full border-2 transition-all duration-300
                        {{ $i < $currentStep ? 'bg-success border-success' : 'border-neutral-200 dark:border-coolgray-300' }}
                        {{ $i === $currentStep ? 'bg-white dark:bg-coolgray-500' : '' }}
                       ">
                        @if ($i < $currentStep)
                            <svg class="size-5 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                fill="currentColor">
                                <path fill-rule="evenodd"
                                    d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                    clip-rule="evenodd" />
                            </svg>
                        @else
                            <span
                                class="text-sm font-bold text-black dark:text-white">
                                {{ $i }}
                            </span>
                        @endif
                    </div>
                    <span
                        class="mt-2 text-xs font-medium text-black dark:text-white">
                        @if ($i === 1)
                            Server
                        @elseif ($i === 2)
                            Connection
                        @elseif ($i === 3)
                            Complete
                        @endif
                    </span>
                </div>
                @if ($i < $totalSteps)
                    <div
                        class="flex-1 h-0.5 mx-4 transition-all duration-300
                        {{ $i < $currentStep ? 'bg-success' : 'bg-neutral-200 dark:bg-coolgray-300' }}">
                    </div>
                @endif
            </div>
        @endfor
    </div>
</div>
