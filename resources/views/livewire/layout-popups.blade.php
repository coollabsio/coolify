<div x-data="{
    popups: {
        sponsorship: true,
        notification: true,
        realtime: false,
    },
    isDevelopment: {{ isDev() ? 'true' : 'false' }},
    init() {
        this.popups.sponsorship = this.shouldShowMonthlyPopup('popupSponsorship');
        this.popups.notification = this.shouldShowMonthlyPopup('popupNotification');
        this.popups.realtime = localStorage.getItem('popupRealtime');

        let checkNumber = 1;
        let checkPusherInterval = null;
        let checkReconnectInterval = null;

        if (!this.popups.realtime) {
            checkPusherInterval = setInterval(() => {
                if (window.Echo) {
                    if (window.Echo.connector.pusher.connection.state === 'connected') {
                        this.popups.realtime = false;
                    } else {
                        checkNumber++;
                        if (checkNumber > 5) {
                            this.popups.realtime = true;
                            console.error(
                                'Coolify could not connect to its real-time service. This will cause unusual problems on the UI if not fixed! Please check the related documentation (https://coolify.io/docs/knowledge-base/cloudflare/tunnels/overview) or get help on Discord (https://coollabs.io/discord).)'
                            );
                        }

                    }
                }
            }, 2000);
        }
    },
    shouldShowMonthlyPopup(storageKey) {
        const disabledTimestamp = localStorage.getItem(storageKey);

        // If never disabled, show the popup
        if (!disabledTimestamp || disabledTimestamp === 'false') {
            return true;
        }

        // If disabled timestamp is not a valid number, show the popup
        const disabledTime = parseInt(disabledTimestamp);
        if (isNaN(disabledTime)) {
            return true;
        }

        const now = new Date();
        const disabledDate = new Date(disabledTime);

        {{-- if (this.isDevelopment) {
            // In development: check if 10 seconds have passed
            const timeDifference = now.getTime() - disabledDate.getTime();
            const tenSecondsInMs = 10 * 1000;
            return timeDifference >= tenSecondsInMs;
        } else { --}}
        // In production: check if we're in a different month or year
        const isDifferentMonth = now.getMonth() !== disabledDate.getMonth() ||
            now.getFullYear() !== disabledDate.getFullYear();
        return isDifferentMonth;
        {{-- } --}}
    }
}">
    @auth
        <span x-show="popups.realtime === true">
            @if (!isCloud())
                <x-popup>
                    <x-slot:title>
                        <span class="font-bold text-left text-red-500">WARNING: </span> Cannot connect to real-time service
                    </x-slot:title>
                    <x-slot:description>
                        <div>This will cause unusual problems on the
                            UI! <br><br>
                            Please ensure that you have opened the
                            <a class="underline" href='https://coolify.io/docs/knowledge-base/server/firewall'
                                target='_blank'>required ports</a> or get
                            help on <a class="underline" href='https://coollabs.io/discord' target='_blank'>Discord</a>.
                        </div>
                    </x-slot:description>
                    <x-slot:button-text @click="disableRealtime()">
                        Acknowledge & Disable This Popup
                    </x-slot:button-text>
                </x-popup>
            @endif
        </span>
    @endauth
    @if (instanceSettings()->is_sponsorship_popup_enabled && !isCloud())
        <span x-show="popups.sponsorship">
            <x-popup>
                <x-slot:customActions>
                    <div
                        class="flex md:flex-row flex-col max-w-4xl p-6 mx-auto bg-white border shadow-lg lg:border-t dark:border-coolgray-300 border-neutral-200 dark:bg-coolgray-100 lg:p-8 lg:pb-4 sm:rounded-sm gap-2">
                        <div class="md:block hidden">
                            <img src="{{ asset('heart.png') }}" class="w-20 h-20">
                        </div>
                        <div class="flex flex-col gap-2 lg:px-10 px-1">
                            <div class="lg:text-xl text-md dark:text-white font-bold">Love Coolify? Support our work.
                            </div>
                            <div class="lg:text-sm text-xs dark:text-white">
                                We are already profitable thanks to <span class="font-bold text-pink-500">YOU</span>
                                but...<br />We
                                would
                                like to
                                make
                                more cool features.
                            </div>
                            <div class="lg:text-sm text-xs dark:text-white pt-2 ">
                                For this we need your help to support our work financially.
                            </div>
                        </div>
                        <div class="flex flex-col gap-2 text-center md:mx-auto lg:py-0 pt-2">
                            <x-forms.button isHighlighted class="md:w-36 w-full"><a target="_blank"
                                    href="https://github.com/sponsors/coollabsio"
                                    class="font-bold dark:text-white">GitHub
                                    Sponsors</a></x-forms.button>
                            <x-forms.button isHighlighted class="md:w-36 w-full"><a target="_blank"
                                    href="https://opencollective.com/coollabsio/donate?interval=month&amount=10&name=&legalName=&email="
                                    class="font-bold dark:text-white">Open
                                    Collective</a></x-forms.button>
                            <x-forms.button isHighlighted class="md:w-36 w-full"><a
                                    href="https://donate.stripe.com/8x2bJ104ifmB9kB45u38402" target="_blank"
                                    class="font-bold dark:text-white">Stripe</a></x-forms.button>
                            <div class="pt-4 dark:text-white hover:underline cursor-pointer lg:text-base text-xs"
                                @click="bannerVisible=false;disableSponsorship()">
                                Maybe next time
                            </div>
                        </div>
                    </div>
                </x-slot:customActions>
            </x-popup>
        </span>
    @endif
    @if (currentTeam()->subscriptionPastOverDue())
        <x-banner :closable=false>
            <div><span class="font-bold text-red-500">WARNING:</span> Your subscription is in over-due. If your
                latest
                payment is not paid within a week, all automations <span class="font-bold text-red-500">will
                    be deactivated</span>. Visit <a href="{{ route('subscription.show') }}"
                    class="underline dark:text-white">/subscription</a> to check your subscription status or pay
                your
                invoice (or check your email for the invoice).
            </div>
        </x-banner>
    @endif
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
            // Store current timestamp instead of just 'false'
            localStorage.setItem('popupSponsorship', Date.now().toString());
        }

        function disableNotification() {
            // Store current timestamp instead of just 'false'
            localStorage.setItem('popupNotification', Date.now().toString());
        }

        function disableRealtime() {
            localStorage.setItem('popupRealtime', 'disabled');
        }
    </script>
</div>
