<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2 ">
            <h2>{{ __('resource_limits.title') }}</h2>
            <x-forms.button canGate="update" :canResource="$resource" type='submit'>{{ __('button.save') }}</x-forms.button>
        </div>
        <div class="">{{ __('resource_limits.description') }}</div>
        <h3 class="pt-4">{{ __('resource_limits.cpu_title') }}</h3>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$resource" placeholder="1.5"
                helper="{{ __('resource_limits.cpu_count_helper') }}"
                label="{{ __('resource_limits.cpu_count_label') }}" id="limitsCpus" />
            <x-forms.input canGate="update" :canResource="$resource" placeholder="0-2"
                helper="{{ __('resource_limits.cpu_sets_helper') }}"
                label="{{ __('resource_limits.cpu_sets_label') }}" id="limitsCpuset" />
            <x-forms.input canGate="update" :canResource="$resource" placeholder="1024"
                helper="{{ __('resource_limits.cpu_weight_helper') }}"
                label="{{ __('resource_limits.cpu_weight_label') }}" id="limitsCpuShares" />
        </div>
        <h3 class="pt-4">{{ __('resource_limits.memory_title') }}</h3>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$resource"
                    helper="{{ __('resource_limits.memory_soft_helper') }}"
                    label="{{ __('resource_limits.memory_soft_label') }}" id="limitsMemoryReservation" />
                <x-forms.input canGate="update" :canResource="$resource"
                    helper="{{ __('resource_limits.swappiness_helper') }}"
                    type="number" min="0" max="100" label="{{ __('resource_limits.swappiness_label') }}"
                    id="limitsMemorySwappiness" />
            </div>
            <div class="flex gap-2">
                <x-forms.input canGate="update" :canResource="$resource"
                    helper="{{ __('resource_limits.memory_max_helper') }}"
                    label="{{ __('resource_limits.memory_max_label') }}" id="limitsMemory" />
                <x-forms.input canGate="update" :canResource="$resource"
                    helper="{{ __('resource_limits.swap_max_helper') }}"
                    label="{{ __('resource_limits.swap_max_label') }}" id="limitsMemorySwap" />
            </div>
        </div>
    </form>
</div>
