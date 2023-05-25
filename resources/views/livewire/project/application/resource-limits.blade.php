<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex gap-2">
            <h2>Resource Limits</h2>
            <x-forms.button type='submit'>Save</x-forms.button>
        </div>
        <div>Limit your container resources by CPU & memory.</div>
        <h3>CPU</h3>
        <x-forms.input placeholder="1.5" label="Number of CPUs" id="application.limits_cpus" />
        <x-forms.input placeholder="0-2" label="CPU set to use" id="application.limits_cpuset" />
        <x-forms.input placeholder="1024" label="CPU Weight" id="application.limits_cpu_shares" />
        <h3>Memory</h3>
        <x-forms.input placeholder="69b or 420k or 1337m or 1g" label="Limit" id="application.limits_memory" />
        <x-forms.input placeholder="69b or 420k or 1337m or 1g" label="Swap" id="application.limits_memory_swap" />
        <x-forms.input placeholder="0-100" type="number" min="0" max="100" label="Swappiness"
            id="application.limits_memory_swappiness" />
        <x-forms.input placeholder="69b or 420k or 1337m or 1g" label="Soft Limit"
            id="application.limits_memory_reservation" />
        <x-forms.checkbox label="Disable OOM kill" id="application.limits_memory_oom_kill" />
    </form>
</div>
