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
                            if (checkNumber > 4) {
                                @if ($isNotificationEnabled)
                                    $wire.showNotification = true;
                                @endif
                                console.error(
                                    'Coolify could not connect to the new realtime service introduced in beta.154. This will cause unusual problems on the UI if not fixed! Please check the related documentation (https://coolify.io/docs/cloudflare/tunnels) or get help on Discord (https://coollabs.io/discord).)'
                                );
                                clearInterval(checkPusherInterval);
                            }
                        } else {
                            console.log('Coolify Realtime Service is connected!');
                            clearInterval(checkPusherInterval);
                        }
                    } else {
                        @if ($isNotificationEnabled)
                            $wire.showNotification = true;
                        @endif
                        console.error(
                            'Coolify could not connect to the new realtime service introduced in beta.154. This will cause unusual problems on the UI if not fixed! Please check the related documentation (https://coolify.io/docs/cloudflare/tunnels) or get help on Discord (https://coollabs.io/discord).)'
                        );
                        clearInterval(checkPusherInterval);
                    }
                }, 1000);
            </script>
        @endscript
        <div class="toast z-[9999]" x-cloak x-show="showNotification">
            <div class="flex flex-col text-white border border-red-500 border-dashed rounded alert bg-coolgray-200">
                <span><span class="font-bold text-left text-red-500">WARNING: </span>Coolify could not connect to the new
                    realtime service introduced in beta.154. <br>This will cause unusual problems on the UI if not
                    fixed!<br><br>Please check the
                    related <a href='https://coolify.io/docs/cloudflare/tunnels' target='_blank'>documentation</a> or get
                    help on <a href='https://coollabs.io/discord' target='_blank'>Discord</a>.</span>
                <x-forms.button class="bg-coolgray-400" wire:click='disable'>Acknowledge the problem and disable this
                    popup</x-forms.button>
            </div>
        </div>
    @endif
</div>
