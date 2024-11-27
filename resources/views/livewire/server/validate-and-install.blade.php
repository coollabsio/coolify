<div class="flex flex-col gap-4">
    {{-- Progress Steps --}}
    <div class="flex flex-col gap-2 mb-4">
        <div class="text-lg font-semibold">Installation Progress</div>
        <div class="grid grid-cols-1 gap-2">
            @foreach($steps as $key => $step)
                <div class="flex items-center gap-2 p-2 rounded-lg {{ $currentStep === $key ? 'bg-primary/10' : '' }}">
                    @if($step['completed'])
                        <svg class="w-5 h-5 text-success" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                            <g fill="currentColor">
                                <path d="m237.66 85.26l-128.4 128.4a8 8 0 0 1-11.32 0l-71.6-72a8 8 0 0 1 0-11.31l24-24a8 8 0 0 1 11.32 0l36.68 35.32a8 8 0 0 0 11.32 0l92.68-91.32a8 8 0 0 1 11.32 0l24 23.6a8 8 0 0 1 0 11.31" opacity=".2"/>
                                <path d="m243.28 68.24l-24-23.56a16 16 0 0 0-22.58 0L104 136l-.11-.11l-36.64-35.27a16 16 0 0 0-22.57.06l-24 24a16 16 0 0 0 0 22.61l71.62 72a16 16 0 0 0 22.63 0l128.4-128.38a16 16 0 0 0-.05-22.67M103.62 208L32 136l24-24l.11.11l36.64 35.27a16 16 0 0 0 22.52 0L208.06 56L232 79.6Z"/>
                            </g>
                        </svg>
                    @elseif($currentStep === $key)
                        <div class="w-5 h-5">
                            <div class="w-5 h-5 border-2 border-primary border-t-transparent rounded-full animate-spin"></div>
                        </div>
                    @else
                        <div class="w-5 h-5 border-2 border-gray-300 rounded-full"></div>
                    @endif
                    <span class="flex-1">{{ $step['label'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Current Step Details --}}
    <div class="flex flex-col gap-2">
        @if($currentStep === 'connection')
            <div class="flex items-center gap-2">
                <span>Checking server connection...</span>
                @if($steps['connection']['completed'])
                    <span class="text-success">Connected!</span>
                @endif
            </div>
        @endif

        @if($currentStep === 'os' || $steps['os']['completed'])
            <div class="flex items-center gap-2">
                <span>Operating System:</span>
                @if($supported_os_type)
                    <span class="text-success">{{ $supported_os_type }}</span>
                @endif
            </div>
        @endif

        @if($currentStep === 'docker' || $steps['docker']['completed'])
            <div class="flex items-center gap-2">
                <span>Docker Engine:</span>
                @if($docker_installed)
                    <span class="text-success">Installed</span>
                @elseif($currentStep === 'docker')
                    <span>Checking installation...</span>
                @endif
            </div>
        @endif

        @if($currentStep === 'compose' || $steps['compose']['completed'])
            <div class="flex items-center gap-2">
                <span>Docker Compose:</span>
                @if($docker_compose_installed)
                    <span class="text-success">Installed</span>
                @elseif($currentStep === 'compose')
                    <span>Checking installation...</span>
                @endif
            </div>
        @endif

        @if($currentStep === 'dependencies' || $steps['dependencies']['completed'])
            <div class="flex items-center gap-2">
                <span>Dependencies:</span>
                @if($steps['dependencies']['completed'])
                    <span class="text-success">Installed</span>
                @elseif($currentStep === 'dependencies')
                    <span>Installing...</span>
                @endif
            </div>
        @endif

        @if($currentStep === 'proxy' || $steps['proxy']['completed'])
            <div class="flex items-center gap-2">
                <span>Proxy:</span>
                @if($proxy_started)
                    <span class="text-success">Started</span>
                @elseif($currentStep === 'proxy')
                    <span>Starting...</span>
                @endif
            </div>
        @endif
    </div>

    {{-- Error Display --}}
    @if($error)
        <div class="p-4 mt-4 text-sm text-error bg-error/10 rounded-lg">
            <pre class="whitespace-pre-line">{{ $error }}</pre>
        </div>
    @endif

    {{-- Activity Monitor --}}
    <div class="mt-4">
        <livewire:new-activity-monitor header="Installation Logs" />
    </div>
</div>
