<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2 ">
            <h2>Resource Limits</h2>
            <x-forms.button type='submit'>Save</x-forms.button>
        </div>
        <div class="">Limit your container resources by CPU & memory.</div>
        <h3 class="pt-4">Limit CPUs</h3>
        <div class="flex gap-2">
            <x-forms.input placeholder="1.5"
                helper="0 means use all CPUs. Floating point number, like 0.002 or 1.5. More info <a class='dark:text-white underline' target='_blank' href='https://docs.docker.com/engine/reference/run/#cpu-share-constraint'>here</a>."
                label="Number of CPUs" id="resource.limits_cpus" />
            <x-forms.input placeholder="0-2"
                helper="Empty means, use all CPU sets. 0-2 will use CPU 0, CPU 1 and CPU 2. More info <a class='dark:text-white underline'  target='_blank' href='https://docs.docker.com/engine/reference/run/#cpu-share-constraint'>here</a>."
                label="CPU sets to use" id="resource.limits_cpuset" />
            <x-forms.input placeholder="1024"
                helper="More info <a class='dark:text-white underline' target='_blank' href='https://docs.docker.com/engine/reference/run/#cpu-share-constraint'>here</a>."
                label="CPU Weight" id="resource.limits_cpu_shares" />
        </div>
        <h3 class="pt-4">Limit Memory</h3>
        <div class="flex gap-2">
            <x-forms.input placeholder="69b or 420k or 1337m or 1g" label="Soft Memory Limit"
                id="resource.limits_memory_reservation" />
            <x-forms.input placeholder="69b or 420k or 1337m or 1g" label="Maximum Memory Limit"
                id="resource.limits_memory" />
            <x-forms.input placeholder="69b or 420k or 1337m or 1g" label="Maximum Swap Limit"
                id="resource.limits_memory_swap" />
            <x-forms.input placeholder="0-100" type="number" min="0" max="100" label="Swappiness"
                id="resource.limits_memory_swappiness" />
        </div>
    </form>
</div>
