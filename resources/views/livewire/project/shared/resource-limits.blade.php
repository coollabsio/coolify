<div>
    <form wire:submit='submit' class="flex flex-col gap-1">
        <div class="flex items-center gap-2">
            <h2>Resource Limits</h2>
            <x-forms.button canGate="update" :canResource="$resource" type='submit'>Save</x-forms.button>
        </div>
        <div class="flex flex-col gap-8">
            <div class="">Limit your container resources by CPU & memory.</div>
            <div class="flex flex-col gap-3">
                <h3>Limit CPUs</h3>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col md:flex-row gap-4">
                        <x-forms.input canGate="update" :canResource="$resource" type="number" min="0" step="0.1"
                            placeholder="1.5"
                            helper="Limit how much CPU the container can use. 0 means unlimited (use all available CPUs). Use decimal numbers like 1.5 for one and a half CPUs, or 0.5 for half a CPU.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/engine/reference/run/#cpu-quota-constraint'>cpu-quota</a>."
                            label="CPU Limit" id="limitsCpus">
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
                                    <path d="M12 9v6" />
                                    <path d="M9 12h6" />
                                </svg>
                            </x-slot:suffix>
                        </x-forms.input>
                    </div>
                    <div class="flex flex-col md:flex-row gap-4">
                        <x-forms.input canGate="update" :canResource="$resource" placeholder="0-2"
                            helper="Pin container to specific CPU threads. 0 means use all threads. Example: 0-1,4 results in using threads 0,1,4.<br>More info <a class='underline dark:text-white'  target='_blank' href='https://docs.docker.com/engine/reference/run/#cpuset-constraint'>cpuset</a>."
                            label="CPU sets to use" id="limitsCpuset">
                            <x-slot:suffix>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M9 4a3 3 0 0 1 6 0c0 1.657 -1.343 3 -3 3s-3 -1.343 -3 -3" />
                                    <path d="M12 7v13" />
                                    <path d="M9 20h6" />
                                </svg>
                            </x-slot:suffix>
                        </x-forms.input>
                        <x-forms.input canGate="update" :canResource="$resource" type="number" min="0" step="64"
                            placeholder="1024"
                            helper="Relative CPU priority when containers compete for resources. Default: 1024 (normal). Examples: 512 = half priority, 2048 = double priority.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/engine/reference/run/#cpu-share-constraint'>cpu_shares</a>."
                            label="CPU Weight" id="limitsCpuShares">
                            <x-slot:suffix>
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                                    <path d="M5 5m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                                    <path d="M19 5m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                                    <path d="M5 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                                    <path d="M19 19m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" />
                                    <path d="M5 7l0 10" />
                                    <path d="M19 7l0 10" />
                                    <path d="M7 5l10 0" />
                                    <path d="M7 19l10 0" />
                                </svg>
                            </x-slot:suffix>
                        </x-forms.input>
                    </div>
                </div>
            </div>
            <div class="flex flex-col gap-3">
                <h3>Limit Memory</h3>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col md:flex-row gap-4">
                        <x-forms.input-with-select canGate="update" :canResource="$resource"
                            type="number"
                            min="0"
                            placeholder="512"
                            helper="Hard limit on container memory usage. The container will be killed if it exceeds this limit.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#mem_limit'>mem_limit</a>."
                            label="Maximum Memory Limit" id="limitsMemory"
                            :options="['b' => 'B', 'k' => 'KiB', 'm' => 'MiB', 'g' => 'GiB']"
                            defaultOption="m" />
                        <x-forms.input-with-select canGate="update" :canResource="$resource"
                            type="number"
                            min="0"
                            placeholder="256"
                            helper="Guaranteed memory reservation for the container. Docker attempts to ensure this amount is always available.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#mem_reservation'>mem_reservation</a>."
                            label="Soft Memory Limit" id="limitsMemoryReservation"
                            :options="['b' => 'B', 'k' => 'KiB', 'm' => 'MiB', 'g' => 'GiB']"
                            defaultOption="m" />
                    </div>
                    <div class="flex flex-col md:flex-row gap-4">
                        <x-forms.input-with-select canGate="update" :canResource="$resource"
                            type="number"
                            min="0"
                            placeholder="512"
                            helper="Total limit for memory plus swap space. Combined limit for both RAM and swap usage.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#memswap_limit'>memswap_limit</a>."
                            label="Maximum Swap Limit" id="limitsMemorySwap"
                            :options="['b' => 'B', 'k' => 'KiB', 'm' => 'MiB', 'g' => 'GiB']"
                            defaultOption="m" />
                        <x-forms.input canGate="update" :canResource="$resource"
                            placeholder="60"
                            helper="Control how aggressively the kernel swaps memory. 0 = swap only when necessary, 100 = swap aggressively. Default: 60.<br>More info <a class='underline dark:text-white' target='_blank' href='https://docs.docker.com/compose/compose-file/05-services/#mem_swappiness'>mem_swappiness</a>."
                            type="number" min="0" max="100" label="Swappiness"
                            id="limitsMemorySwappiness" suffix="%" />
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
