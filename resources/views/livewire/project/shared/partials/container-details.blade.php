@if ($containers->isNotEmpty())
    <div class="grid grid-cols-1 gap-2">
        <h4>Container Details</h4>
        @foreach ($containers as $container)
            <div class="grid grid-cols-1 gap-2 p-4 border rounded dark:border-coolgray-300 bg-white dark:bg-coolgray-100">
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <div>
                        <div class="text-xs uppercase text-neutral-500">Container ID</div>
                        <div class="font-mono text-sm break-all">{{ data_get($container, 'id') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-neutral-500">Name</div>
                        <div class="font-mono text-sm break-all">{{ data_get($container, 'name') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-neutral-500">Image</div>
                        <div class="font-mono text-sm break-all">{{ data_get($container, 'image') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-neutral-500">Status</div>
                        <div class="font-mono text-sm break-all">{{ data_get($container, 'status') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-neutral-500">Networks</div>
                        <div class="font-mono text-sm break-all">{{ data_get($container, 'networks') }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase text-neutral-500">Ports</div>
                        <div class="font-mono text-sm break-all">{{ data_get($container, 'ports') }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endif
