<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2 ">
            <h2>Resource Limits</h2>
            <x-forms.button canGate="update" :canResource="$resource" type='submit'>Save</x-forms.button>
        </div>
        <div class="">Limit your container resources by CPU & memory.</div>
        <h3 class="pt-4">Limit CPUs</h3>
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$resource" placeholder="1.5"
                helper="0 means use all CPUs. Floating point number, like 0.002 or 1.5. More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/engine/reference/run/#cpu-share-constraint'>cpus</a>."
                label="Number of CPUs" id="limitsCpus" />
            <x-forms.input canGate="update" :canResource="$resource" placeholder="0-2"
                helper="Empty means use all CPU sets. 0-2 will use CPU 0, CPU 1 and CPU 2. More info <a class='underline dark:text-white'  target='_blank' href='https://docs.docker.com/engine/reference/run/#cpu-share-constraint'>cpuset</a>."
                label="CPU sets to use" id="limitsCpuset">
                <x-slot:suffix>
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                        <path d="M5 5m0 1a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1z" />
                        <path d="M9 9h6v6h-6z" />
                        <path d="M9 1v3" />
                        <path d="M15 1v3" />
                        <path d="M9 20v3" />
                        <path d="M15 20v3" />
                        <path d="M20 9h3" />
                        <path d="M20 14h3" />
                        <path d="M1 9h3" />
                        <path d="M1 14h3" />
                    </svg>
                </x-slot:suffix>
            </x-forms.input>
            <x-forms.input canGate="update" :canResource="$resource" placeholder="1024"
                helper="More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/engine/reference/run/#cpu-share-constraint'>cpu_shares</a>."
                label="CPU Weight" id="limitsCpuShares" />
        </div>
        <h3 class="pt-4">Limit Memory</h3>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input-with-select canGate="update" :canResource="$resource"
                    type="number"
                    min="0"
                    helper="Guaranteed memory reservation for the container. Docker attempts to ensure this amount is always available.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#mem_reservation'>mem_reservation</a>."
                    label="Soft Memory Limit" id="limitsMemoryReservation"
                    :options="['b' => 'B', 'k' => 'KiB', 'm' => 'MiB', 'g' => 'GiB']"
                    defaultOption="m" />
                <x-forms.input canGate="update" :canResource="$resource"
                    helper="0-100.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#mem_swappiness'>mem_swappiness</a>."
                    type="number" min="0" max="100" label="Swappiness"
                    id="limitsMemorySwappiness" suffix="%" />
            </div>
            <div class="flex gap-2">
                <x-forms.input-with-select canGate="update" :canResource="$resource"
                    type="number"
                    min="0"
                    helper="Hard limit on container memory usage. The container will be killed if it exceeds this limit.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#mem_limit'>mem_limit</a>."
                    label="Maximum Memory Limit" id="limitsMemory"
                    :options="['b' => 'B', 'k' => 'KiB', 'm' => 'MiB', 'g' => 'GiB']"
                    defaultOption="m" />
                <x-forms.input-with-select canGate="update" :canResource="$resource"
                    type="number"
                    min="0"
                    helper="Total limit for memory plus swap space. Combined limit for both RAM and swap usage.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#memswap_limit'>memswap_limit</a>."
                    label="Maximum Swap Limit" id="limitsMemorySwap"
                    :options="['b' => 'B', 'k' => 'KiB', 'm' => 'MiB', 'g' => 'GiB']"
                    defaultOption="m" />
            </div>
        </div>
    </form>
</div>
