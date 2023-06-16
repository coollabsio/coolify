<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2 ">
            <h2>Resource Limits</h2>
            <x-forms.button type='submit'>Save</x-forms.button>
        </div>
        <div class="">Limit your container resources by CPU & memory.</div>
        <h3 class="pt-4">Limit CPUs</h3>
        <div class="flex gap-2">
            <x-forms.input placeholder="1.5" label="Number of CPUs" id="application.limits_cpus" />
            <x-forms.input placeholder="0-2" label="CPU sets to use" id="application.limits_cpuset" />
            <x-forms.input placeholder="1024" label="CPU Weight" id="application.limits_cpu_shares" />
        </div>
        <h3 class="pt-4">Limit Memory</h3>
        <div class="flex gap-2">
            <x-forms.input placeholder="69b or 420k or 1337m or 1g" label="Soft Memory Limit"
                id="application.limits_memory_reservation" />
            <x-forms.input placeholder="69b or 420k or 1337m or 1g" label="Maximum Memory Limit"
                id="application.limits_memory" />
            <x-forms.input placeholder="69b or 420k or 1337m or 1g" label="Maximum Swap Limit"
                id="application.limits_memory_swap" />
            <x-forms.input placeholder="0-100" type="number" min="0" max="100" label="Swappiness"
                id="application.limits_memory_swappiness" />
        </div>
    </form>
</div>
