<div>
    @if (data_get(auth()->user(), 'is_notification_sponsorship_enabled'))
        <x-popup>
            <x-slot:title>
                Love Coolify as we do?
            </x-slot:title>
            <x-slot:description>
                <span>Please
                    consider donating on <a href="https://github.com/sponsors/coollabsio"
                        class="text-xs text-white underline">GitHub</a> or <a href="https://opencollective.com/coollabsio"
                        class="text-xs text-white underline">OpenCollective</a>.<br><br></span>
                <span>It enables us to keep creating features without paywalls, ensuring our work remains free and
                    open.</span>
            </x-slot:description>
            <x-slot:button-text wire:click='disableSponsorship'>
                Disable This Popup
            </x-slot:button-text>
        </x-popup>
        {{-- <div class="toast">
            <div class="flex flex-col text-white rounded alert bg-coolgray-200">
                <span>Love Coolify as we do? <a href="https://coolify.io/sponsorships"
                        class="underline text-warning">Please
                        consider donating!</a>💜</span>
                <span>It enables us to keep creating features without paywalls, ensuring our work remains free and
                    open.</span>
                <x-forms.button class="bg-coolgray-400" wire:click='disableSponsorship'>Disable This
                    Popup</x-forms.button>
            </div>
        </div> --}}
    @endif
    {{-- <x-popup /> --}}
    @if (currentTeam()->serverOverflow())
        <x-banner :closable=false>
            <div><span class="font-bold text-red-500">WARNING:</span> The number of active servers exceeds the limit
                covered by your payment. If not resolved, some of your servers <span class="font-bold text-red-500">will
                    be deactivated</span>. Visit <a href="{{ route('subscription.show') }}"
                    class="text-white underline">/subscription</a> to update your subscription or remove some servers.
            </div>
        </x-banner>
    @endif
    @if (!currentTeam()->isAnyNotificationEnabled())
        <div class="toast">
            <div class="flex flex-col text-white rounded alert bg-coolgray-200">
                <span><span class="font-bold text-red-500">WARNING:</span> No notifications enabled.<br><br> It is
                    highly recommended to enable at least
                    one
                    notification channel to receive important alerts.<br>Visit <a
                        href="{{ route('notification.index') }}" class="text-white underline">/notification</a> to
                    enable notifications.</span>
                <x-forms.button class="bg-coolgray-400" wire:click='disableNotifications'>Disable This
                    Popup</x-forms.button>
            </div>
        </div>
    @endif
</div>
