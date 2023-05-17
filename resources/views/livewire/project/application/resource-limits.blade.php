<div>
    <h2>Resource Limits</h2>
    <form wire:submit.prevent='submit'>
        <h3>Memory</h3>
        <x-inputs.input placeholder="69b or 420k or 1337m or 1g" label="Limit" id="application.limits_memory" />
        <x-inputs.input placeholder="69b or 420k or 1337m or 1g" label="Swap" id="application.limits_memory_swap" />
        <x-inputs.input placeholder="0-100" type="number" min="0" max="100" label="Swappiness"
            id="application.limits_memory_swappiness" />
        <x-inputs.input placeholder="69b or 420k or 1337m or 1g" label="Soft Limit"
            id="application.limits_memory_reservation" />
        <x-inputs.input type="checkbox" label="Is OOM Kill disabled?" id="application.limits_memory_oom_kill" />
        <h3>CPU</h3>
        <x-inputs.input placeholder="1.5" label="Number of CPUs" id="application.limits_cpus" />
        <x-inputs.input placeholder="0-2" label="CPU set to use" id="application.limits_cpuset" />
        <x-inputs.input placeholder="1024" label="CPU Weight" id="application.limits_cpu_shares" />
        <div class="pt-4">
            <x-inputs.button>Save</x-inputs.button>
        </div>
    </form>
</div>
