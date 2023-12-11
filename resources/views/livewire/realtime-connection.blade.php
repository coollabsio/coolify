<div x-data="{ showNotification: @entangle('showNotification') }">
    @if ($checkConnection)
        @script
            <script>
                let checkPusherInterval = null;
                let checkNumber = 0;
                checkPusherInterval = setInterval(() => {
                    if (window.Echo) {
                        if (window.Echo.connector.pusher.connection.state !== 'connected') {
                            checkNumber++;
                            if (checkNumber > 5) {
                                $wire.showNotification = true;
                                clearInterval(checkPusherInterval);
                            }
                        } else {
                            console.log('Coolify is now connected to the new realtime service introduced in beta.154.');
                            clearInterval(checkPusherInterval);
                            $wire.showNotification = true;
                        }
                    } else {
                        $wire.showNotification = true;
                        clearInterval(checkPusherInterval);
                    }
                }, 2000);
            </script>
        @endscript
        <div class="toast z-[9999]" x-cloak x-show="showNotification">
            <div class="flex flex-col text-white border border-red-500 border-dashed rounded alert bg-coolgray-200">
                <span><span class="font-bold text-left text-red-500">WARNING: </span>Coolify could not connect to the new realtime service introduced in beta.154. <br>This will cause unusual problems on the UI if not fixed!<br><br>Please check the
                    related <a href='https://coolify.io/docs/cloudflare-tunnels' target='_blank'>documentation</a> or get
                    help on <a href='https://coollabs.io/discord' target='_blank'>Discord</a>.</span>
                <x-forms.button class="bg-coolgray-400" wire:click='disable'>Acknowledge the problem and disable this popup</x-forms.button>
            </div>
        </div>
    @endif
</div>
