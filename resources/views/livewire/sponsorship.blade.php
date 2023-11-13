<div>
    @if (data_get(auth()->user(), 'is_notification_sponsorship_enabled'))
        <div class="toast">
            <div class="flex flex-col text-white rounded alert bg-coolgray-200">
                <span>Love Coolify as we do? <a href="https://coolify.io/sponsorships"
                        class="underline text-warning">Please
                        consider donating!</a>💜</span>
                <span>It enables us to keep creating features without paywalls, ensuring our work remains free and
                    open.</span>
                <x-forms.button class="bg-coolgray-400" wire:click='disable'>Disable This Popup</x-forms.button>
            </div>
        </div>
    @endif
</div>
