<div>
    @if (subscriptionProvider() === 'stripe')
        <div class="pt-4">
            <h2>Your current plan</h2>
            <div class="pb-4">Tier: <strong class="dark:text-warning">
                    @if (data_get(currentTeam(), 'subscription')->type() == 'dynamic')
                        Pay-as-you-go
                    @else
                        {{ data_get(currentTeam(), 'subscription')->type() }}
                    @endif

                </strong></div>

            @if (currentTeam()->subscription->stripe_cancel_at_period_end)
                <div class="pb-2">Subscription is active but on cancel period.</div>
            @else
                <div class="pb-2">Subscription is active. Last invoice is
                    {{ currentTeam()->subscription->stripe_invoice_paid ? 'paid' : 'not paid' }}.</div>
            @endif
            <div class="flex items-center gap-2">
                <div class="w-48">Number of paid servers:</div>
                <div class="text-xl font-bold dark:text-white">{{ $server_limits }}</div>
            </div>
            <div class="flex items-center gap-2">
                <div class="w-48">Currently active servers:</div>
                <div class="text-xl font-bold dark:text-white">{{ currentTeam()->servers->count() }}</div>
            </div>
            @if (currentTeam()->serverOverflow())
                <div class="py-4"><span class="font-bold text-red-500">WARNING:</span> You must delete
                    {{ currentTeam()->servers->count() - $server_limits }} servers,
                    or upgrade your subscription. {{ currentTeam()->servers->count() - $server_limits }} servers will be
                    deactivated.</div>
            @endif
            <x-forms.button class="gap-2" wire:click='stripeCustomerPortal'>Change Server Quantity
            </x-forms.button>
            <h2 class="pt-4">Manage your subscription</h2>
            <div class="pb-4">Cancel, upgrade or downgrade your subscription.</div>
            <div class="flex gap-2">
                <x-forms.button class="gap-2" wire:click='stripeCustomerPortal'>Go to <svg
                        xmlns="http://www.w3.org/2000/svg" class="w-12 " viewBox="0 0 512 214">
                        <path fill="#635BFF"
                            d="M512 110.08c0-36.409-17.636-65.138-51.342-65.138c-33.85 0-54.33 28.73-54.33 64.854c0 42.808 24.179 64.426 58.88 64.426c16.925 0 29.725-3.84 39.396-9.244v-28.445c-9.67 4.836-20.764 7.823-34.844 7.823c-13.796 0-26.027-4.836-27.591-21.618h69.547c0-1.85.284-9.245.284-12.658Zm-70.258-13.511c0-16.071 9.814-22.756 18.774-22.756c8.675 0 17.92 6.685 17.92 22.756h-36.694Zm-90.31-51.627c-13.939 0-22.899 6.542-27.876 11.094l-1.85-8.818h-31.288v165.83l35.555-7.537l.143-40.249c5.12 3.698 12.657 8.96 25.173 8.96c25.458 0 48.64-20.48 48.64-65.564c-.142-41.245-23.609-63.716-48.498-63.716Zm-8.534 97.991c-8.391 0-13.37-2.986-16.782-6.684l-.143-52.765c3.698-4.124 8.818-6.968 16.925-6.968c12.942 0 21.902 14.506 21.902 33.137c0 19.058-8.818 33.28-21.902 33.28ZM241.493 36.551l35.698-7.68V0l-35.698 7.538V36.55Zm0 10.809h35.698v124.444h-35.698V47.36Zm-38.257 10.524L200.96 47.36h-30.72v124.444h35.556V87.467c8.39-10.951 22.613-8.96 27.022-7.396V47.36c-4.551-1.707-21.191-4.836-29.582 10.524Zm-71.112-41.386l-34.702 7.395l-.142 113.92c0 21.05 15.787 36.551 36.836 36.551c11.662 0 20.195-2.133 24.888-4.693V140.8c-4.55 1.849-27.022 8.391-27.022-12.658V77.653h27.022V47.36h-27.022l.142-30.862ZM35.982 83.484c0-5.546 4.551-7.68 12.09-7.68c10.808 0 24.461 3.272 35.27 9.103V51.484c-11.804-4.693-23.466-6.542-35.27-6.542C19.2 44.942 0 60.018 0 85.192c0 39.252 54.044 32.995 54.044 49.92c0 6.541-5.688 8.675-13.653 8.675c-11.804 0-26.88-4.836-38.827-11.378v33.849c13.227 5.689 26.596 8.106 38.827 8.106c29.582 0 49.92-14.648 49.92-40.106c-.142-42.382-54.329-34.845-54.329-50.774Z" />
                    </svg>
                </x-forms.button>
            </div>
        </div>
        <div class="pt-4">
            If you have any problems, please <a class="underline dark:text-white" href="{{ config('constants.urls.contact') }}"
                target="_blank">contact us.</a>
        </div>
    @endif
</div>
