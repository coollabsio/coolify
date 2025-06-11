<div>
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Commands | Coolify
    </x-slot>
    @if ($type === 'application')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <h1>Terminal</h1>
        <livewire:project.application.heading :application="$resource" />
    @elseif ($type === 'database')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <h1>Terminal</h1>
        <livewire:project.database.heading :database="$resource" />
    @elseif ($type === 'service')
        <livewire:project.shared.configuration-checker :resource="$resource" />
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" title="Terminal" />
    @elseif ($type === 'server')
        <livewire:server.navbar :server="$server" />
    @endif

    @if (!$hasShell)
        <div class="flex items-center justify-center w-full py-4 mx-auto">
            <div class="p-4 w-full rounded-sm border dark:bg-coolgray-100 dark:border-coolgray-300">
                <div class="flex flex-col items-center justify-center space-y-4">
                    <svg class="w-12 h-12 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div class="text-center">
                        <h3 class="text-lg font-medium">Terminal Not Available</h3>
                        <p class="mt-2 text-sm text-gray-500">No shell (bash/sh) is available in this container. Please
                            ensure either bash or sh is installed to use the terminal.</p>
                    </div>
                </div>
            </div>
        </div>
    @else
        @if ($type === 'server')
            @if ($server->isTerminalEnabled())
                <form class="w-full flex gap-2 items-start justify-start"
                    wire:submit="$dispatchSelf('connectToServer')">
                    <h2 class="pb-4">Terminal</h2>
                    <x-forms.button type="submit" :disabled="$isConnecting">
                        Reconnect
                    </x-forms.button>
                </form>

                {{-- Loading indicator for all connection states --}}
                @if (!$containersLoaded || $isConnecting || $connectionStatus)
                    <span class="text-sm">{{ $connectionStatus }}</span>
                @endif

                <div class="mx-auto w-full">
                    <livewire:project.shared.terminal wire:key="terminal-{{ $this->getId() }}-server" />
                </div>
            @else
                <div>Terminal access is disabled on this server.</div>
            @endif
        @else
            @if (count($containers) === 0)
                <div class="pt-4">No containers are running on this server or terminal access is disabled.</div>
            @else
                @if (count($containers) === 1)
                    <form class="w-full flex gap-2 items-start justify-start pt-4"
                        wire:submit="$dispatchSelf('connectToContainer')">
                        <h2 class="pb-4">Terminal</h2>
                        <x-forms.button type="submit" :disabled="$isConnecting">
                            Reconnect
                        </x-forms.button>
                    </form>

                    {{-- Loading indicator for all connection states --}}
                    @if (!$containersLoaded || $isConnecting || $connectionStatus)
                        <span class="text-sm">{{ $connectionStatus }}</span>
                    @endif
                @else
                    <form class="w-full pt-4 flex gap-2 flex-col" wire:submit="$dispatchSelf('connectToContainer')">
                        <x-forms.select label="Container" id="container" required wire:model="selected_container">
                            @foreach ($containers as $container)
                                @if ($loop->first)
                                    <option disabled value="default">Select a container</option>
                                @endif
                                <option value="{{ data_get($container, 'container.Names') }}">
                                    {{ data_get($container, 'container.Names') }}
                                    ({{ data_get($container, 'server.name') }})
                                </option>
                            @endforeach
                        </x-forms.select>
                        <x-forms.button class="w-full" type="submit" :disabled="$isConnecting">
                            {{ $isConnecting ? 'Connecting...' : 'Connect' }}
                        </x-forms.button>
                    </form>

                    {{-- Loading indicator for manual connection --}}
                    @if ($isConnecting || $connectionStatus)
                        <span class="text-sm">{{ $connectionStatus }}</span>
                    @endif
                @endif
                <div class="mx-auto w-full">
                    <livewire:project.shared.terminal />
                </div>
            @endif
        @endif
    @endif

    @script
        <script>
            let autoConnectionAttempted = false;
            let maxRetries = 5;
            let currentRetry = 0;

            // Robust component readiness check
            function isComponentReady() {
                // Check if Livewire component exists and is properly initialized
                if (!$wire || typeof $wire.call !== 'function') {
                    return false;
                }

                // Check if terminal container exists
                const terminalContainer = document.getElementById('terminal-container');
                if (!terminalContainer) {
                    return false;
                }

                // Check if Alpine component is initialized
                if (!terminalContainer._x_dataStack || !terminalContainer._x_dataStack[0]) {
                    return false;
                }

                return true;
            }

            // Safe connection with retries
            function attemptConnection() {
                if (autoConnectionAttempted) return;

                if (!isComponentReady()) {
                    currentRetry++;
                    if (currentRetry < maxRetries) {
                        console.log(`[Terminal] Component not ready, retry ${currentRetry}/${maxRetries}`);
                        setTimeout(attemptConnection, 1000);
                        return;
                    } else {
                        console.error('[Terminal] Max retries reached, giving up auto-connection');
                        return;
                    }
                }

                autoConnectionAttempted = true;
                console.log('[Terminal] Attempting auto-connection');

                try {
                    // Use a safer method that doesn't trigger re-renders
                    $wire.call('initializeTerminalConnection').catch(error => {
                        console.error('[Terminal] Auto-connection failed:', error);
                        // Reset for manual retry
                        autoConnectionAttempted = false;
                    });
                } catch (error) {
                    console.error('[Terminal] Auto-connection failed immediately:', error);
                    // Reset for manual retry
                    autoConnectionAttempted = false;
                }
            }

            // Wait for Livewire to be fully initialized
            function waitForLivewire() {
                if (window.Livewire && window.Livewire.all().length > 0) {
                    // Extra delay in production to ensure stability
                    const isProduction = @js(app()->environment('production'));
                    const delay = isProduction ? 2000 : 1000;
                    setTimeout(attemptConnection, delay);
                } else {
                    setTimeout(waitForLivewire, 200);
                }
            }

            // Multiple initialization triggers for robustness
            document.addEventListener('DOMContentLoaded', waitForLivewire);

            // Livewire-specific events
            document.addEventListener('livewire:init', () => {
                setTimeout(waitForLivewire, 500);
            });

            document.addEventListener('livewire:navigated', () => {
                autoConnectionAttempted = false;
                currentRetry = 0;
                setTimeout(waitForLivewire, 500);
            });

            // Additional safety net for production
            const isProduction = @js(app()->environment('production'));
            if (isProduction) {
                window.addEventListener('load', () => {
                    if (!autoConnectionAttempted) {
                        setTimeout(waitForLivewire, 1000);
                    }
                });

                // Emergency fallback - disable auto-connection if too many errors
                let errorCount = 0;
                window.addEventListener('error', (event) => {
                    if (event.message && event.message.includes('Snapshot missing')) {
                        errorCount++;
                        console.warn(`[Terminal] Snapshot error detected (${errorCount})`);
                        if (errorCount >= 3) {
                            console.error('[Terminal] Too many snapshot errors, disabling auto-connection');
                            autoConnectionAttempted = true; // Prevent further attempts
                        }
                    }
                });
            }
        </script>
    @endscript
</div>
