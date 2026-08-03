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
                        class="relative mx-auto flex w-full max-w-2xl flex-col gap-5 overflow-hidden rounded-2xl border border-neutral-200 bg-white p-5 shadow-modal sm:p-6 dark:border-white/[0.1] dark:bg-surface">
                        <button type="button" aria-label="Dismiss sponsorship reminder"
                            class="absolute top-3 right-3 flex size-7 items-center justify-center rounded-full text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.07] dark:hover:text-fg"
                            @click="bannerVisible=false;disableSponsorship()">
                            <x-reicon name="x" class="size-3.5" />
                        </button>

                        <div class="flex items-start gap-4 pr-8">
                            <div
                                class="hidden size-12 shrink-0 items-center justify-center rounded-xl border border-neutral-200 bg-neutral-50 sm:flex dark:border-white/[0.08] dark:bg-white/[0.04]">
                                <img src="{{ asset('heart.png') }}" alt="" class="size-9">
                            </div>
                            <div class="min-w-0">
                                <h2 class="text-[15px]! leading-5! font-semibold! text-black dark:text-fg">
                                    Love Coolify? Support our work.
                                </h2>
                                <p class="mt-1 text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                                    Coolify is profitable thanks to <span
                                        class="font-semibold text-coollabs dark:text-warning">you</span>. Your support
                                    helps us build more features and keep improving the project.
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <a target="_blank" href="https://github.com/sponsors/coollabsio"
                                class="button h-9 justify-center bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! sm:flex-1 dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                                GitHub Sponsors
                            </a>
                            <a target="_blank"
                                href="https://opencollective.com/coollabsio/donate?interval=month&amount=10&name=&legalName=&email="
                                class="button h-9 justify-center sm:flex-1">
                                Open Collective
                            </a>
                            <a href="https://donate.stripe.com/8x2bJ104ifmB9kB45u38402" target="_blank"
                                class="button h-9 justify-center sm:flex-1">
                                Stripe
                            </a>
                            <button type="button"
                                class="h-9 cursor-pointer px-2 text-[12px] font-medium text-neutral-500 transition-colors hover:text-black sm:shrink-0 dark:text-fg-dim dark:hover:text-fg"
                                @click="bannerVisible=false;disableSponsorship()">
                                Maybe next time
                            </button>
                        </div>
                    </div>
                </x-slot:customActions>
            </x-popup>
        </span>
    @endif
    @if (request()->query->get('cancelled'))
        <x-banner>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-red-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                        clip-rule="evenodd" />
                </svg>
                <span><span class="font-bold text-red-500">Subscription Error.</span> Something went wrong. Please try
                    again or <a class="underline dark:text-white"
                        href="{{ config('constants.urls.contact') }}" target="_blank">contact support</a>.</span>
            </div>
        </x-banner>
    @endif
    @if (request()->query->get('success'))
        <x-banner>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-green-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                        clip-rule="evenodd" />
                </svg>
                <span><span class="font-bold text-green-500">Welcome onboard!</span> Your subscription has been
                    activated. It could take a few seconds before it's fully active.</span>
            </div>
        </x-banner>
    @endif
    @if (currentTeam()->subscriptionPastOverDue())
        <x-banner :closable=false>
            <div><span class="font-bold text-red-500">WARNING:</span> Your subscription is in over-due. If your
                latest
                payment is not paid within a week, all automations <span class="font-bold text-red-500">will
                    be deactivated</span>. Visit <a href="{{ route('subscription.show') }}" {{ wireNavigate() }}
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
                    be deactivated</span>. Visit <a href="{{ route('subscription.show') }}" {{ wireNavigate() }}
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
                        href="{{ route('notifications.email') }}" {{ wireNavigate() }} class="underline dark:text-white">/notification</a> to
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
