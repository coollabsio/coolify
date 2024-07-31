<div x-data="{
    popups: {
        sponsorship: true,
        notification: true,
        realtime: false,
    },
    init() {
        this.popups.sponsorship = localStorage.getItem('popupSponsorship') !== 'false';
        this.popups.notification = localStorage.getItem('popupNotification') !== 'false';
        this.popups.realtime = localStorage.getItem('popupRealtime');

        let checkNumber = 1;
        let checkPusherInterval = null;
        if (!this.popups.realtime) {
            checkPusherInterval = setInterval(() => {
                if (window.Echo && window.Echo.connector.pusher.connection.state !== 'connected') {
                    checkNumber++;
                    if (checkNumber > 5) {
                        this.popups.realtime = true;
                        console.error(
                            'Coolify could not connect to its real-time service. This will cause unusual problems on the UI if not fixed! Please check the related documentation (https://coolify.io/docs/knowledge-base/cloudflare/tunnels) or get help on Discord (https://coollabs.io/discord).)'
                        );
                        clearInterval(checkPusherInterval);
                    }
                }
            }, 2000);
        }
    }
}">
    @auth
        <span x-show="popups.realtime === true">
            @if (!isCloud())
                <x-popup>
                    <x-slot:title>
                        <span class="font-bold text-left text-red-500">WARNING: </span>Realtime Error?!
                    </x-slot:title>
                    <x-slot:description>
                        <span>Coolify could not connect to its real-time service.<br>This will cause unusual problems on the
                            UI
                            if
                            not fixed! <br><br>
                            Please ensure that you have opened the
                            <a class="underline" href='https://coolify.io/docs/knowledge-base/server/firewall'
                                target='_blank'>required ports</a>,
                            check the
                            related <a class="underline" href='https://coolify.io/docs/knowledge-base/cloudflare/tunnels'
                                target='_blank'>documentation</a> or get
                            help on <a class="underline" href='https://coollabs.io/discord' target='_blank'>Discord</a>.
                        </span>
                    </x-slot:description>
                    <x-slot:button-text @click="disableRealtime()">
                        Acknowledge & Disable This Popup
                    </x-slot:button-text>
                </x-popup>
            @endif
        </span>
    @endauth
    <span x-show="popups.sponsorship">
        <x-popup>
            <x-slot:title>
                Love Coolify as we do?
            </x-slot:title>
            <x-slot:icon>
                <img src="https://cdn-icons-png.flaticon.com/512/8236/8236748.png"
                    class="w-8 h-8 sm:w-12 sm:h-12 lg:w-16 lg:h-16">
            </x-slot:icon>
            <x-slot:description>
                <span>Please
                    consider donating on <a href="https://github.com/sponsors/coollabsio"
                        class="text-xs underline dark:text-white">GitHub</a> or <a
                        href="https://opencollective.com/coollabsio"
                        class="text-xs underline dark:text-white">OpenCollective</a>.<br><br></span>
                <span>It enables us to keep creating features without paywalls, ensuring our work remains free and
                    open.</span>
            </x-slot:description>
            <x-slot:button-text @click="disableSponsorship()">
                Disable This Popup
            </x-slot:button-text>
        </x-popup>
    </span>
    @if (currentTeam()->serverOverflow())
        <x-banner :closable=false>
            <div><span class="font-bold text-red-500">WARNING:</span> The number of active servers exceeds the limit
                covered by your payment. If not resolved, some of your servers <span class="font-bold text-red-500">will
                    be deactivated</span>. Visit <a href="{{ route('subscription.show') }}"
                    class="underline dark:text-white">/subscription</a> to update your subscription or remove some
                servers.
            </div>
        </x-banner>
    @endif
    @if (!currentTeam()->isAnyNotificationEnabled())
        <span x-show="popups.notification">
            <x-popup>
                <x-slot:title>
                    No notifications enabled.
                </x-slot:title>
                <x-slot:icon>
                    <svg xmlns="http://www.w3.org/2000/svg" class="text-red-500 stroke-current w-14 h-14 shrink-0"
                        fill="none" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </x-slot:icon>
                <x-slot:description>
                    It is
                    highly recommended to enable at least
                    one
                    notification channel to receive important alerts.<br>Visit <a
                        href="{{ route('notifications.email') }}" class="underline dark:text-white">/notification</a> to
                    enable notifications.</span>
        </x-slot:description>
        <x-slot:button-text @click="disableNotification()">
            Accept and Close
        </x-slot:button-text>
        </x-popup>
        </span>
    @endif
    <script>
        function disableSponsorship() {
            localStorage.setItem('popupSponsorship', false);
        }

        function disableNotification() {
            localStorage.setItem('popupNotification', false);
        }

        function disableRealtime() {
            localStorage.setItem('popupRealtime', 'disabled');
        }
    </script>
</div>
