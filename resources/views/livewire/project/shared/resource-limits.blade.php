<form wire:submit="submit" class="application-settings-form flex flex-col gap-6">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section id="cpu-limits-section" title="CPU"
        helper="Limit CPU capacity, affinity, and scheduling priority for this container.">
        <div class="grid gap-4 md:grid-cols-3">
            <x-forms.input canGate="update" :canResource="$resource" placeholder="1.5"
                helper="Set to 0 to use all available CPUs. Decimal values such as 0.5 are supported."
                label="CPU limit" id="limitsCpus" />
            <x-forms.input canGate="update" :canResource="$resource" placeholder="0-2"
                helper="Restrict execution to specific cores, for example 0-2 or 0,1,3. Leave empty for all cores."
                label="CPU set" id="limitsCpuset" />
            <x-forms.input canGate="update" :canResource="$resource" placeholder="1024"
                helper="Relative CPU scheduling weight. Docker uses 1024 by default."
                label="CPU weight" id="limitsCpuShares" />
        </div>
        <a class="mt-4 inline-flex items-center gap-1 text-xs text-neutral-500 hover:text-coollabs dark:text-fg-dim dark:hover:text-warning"
            target="_blank" rel="noopener noreferrer"
            href="https://docs.docker.com/engine/containers/resource_constraints/#cpu">
            Docker CPU constraints
            <x-external-link />
        </a>
    </x-application.settings-section>

    <x-application.settings-section id="memory-limits-section" title="Memory"
        helper="Set hard and soft memory limits, swap allowance, and swappiness.">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$resource"
                helper="Soft reservation used when the host is under memory pressure. Use values such as 256m or 1g."
                label="Memory reservation" id="limitsMemoryReservation" />
            <x-forms.input canGate="update" :canResource="$resource"
                helper="Maximum memory available to the container. Set to 0 for unlimited."
                label="Memory limit" id="limitsMemory" />
            <x-forms.input canGate="update" :canResource="$resource"
                helper="Combined memory and swap allowance. Use values such as 512m or 2g."
                label="Memory and swap limit" id="limitsMemorySwap" />
            <x-forms.input canGate="update" :canResource="$resource"
                helper="Controls how aggressively anonymous memory is swapped. Enter a value from 0 to 100."
                type="number" min="0" max="100" label="Swappiness" id="limitsMemorySwappiness" />
        </div>
        <p class="mt-4 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
            Accepted units are <code class="font-mono">b</code>, <code class="font-mono">k</code>,
            <code class="font-mono">m</code>, and <code class="font-mono">g</code>.
        </p>
    </x-application.settings-section>
</form>
