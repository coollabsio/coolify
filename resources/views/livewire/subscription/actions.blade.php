<div wire:init="loadRefundEligibility">
    @if (subscriptionProvider() === 'stripe')
        {{-- Plan Overview --}}
        <section x-data="{
            qty: {{ $quantity }},
            get current() { return $wire.server_limits; },
            activeServers: {{ currentTeam()->servers->count() }},
            preview: @js($pricePreview),
            loading: false,
            showModal: false,
            async fetchPreview() {
                if (this.qty < 2 || this.qty > 100 || this.qty === this.current) { return; }
                this.loading = true;
                this.preview = null;
                await $wire.loadPricePreview(this.qty);
                this.preview = $wire.pricePreview;
                this.loading = false;
            },
            fmt(cents) {
                if (!this.preview) return '';
                const c = this.preview.currency;
                return c === 'USD' ? '$' + (cents / 100).toFixed(2) : (cents / 100).toFixed(2) + ' ' + c;
            },
            get isReduction() { return this.qty < this.activeServers; },
            get hasChanged() { return this.qty !== this.current; },
            get hasPreview() { return this.preview !== null; },
            openAdjust() {
                this.showModal = true;
            },
            closeAdjust() {
                this.showModal = false;
                this.qty = this.current;
                this.preview = null;
            }
        }" @success.window="preview = null; showModal = false; qty = $wire.server_limits"
            @keydown.escape.window="if (showModal) { closeAdjust(); }" class="-mt-2">
            <h3 class="pb-2">Plan Overview</h3>
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
                {{-- Current Plan Card --}}
                <div class="p-5 rounded border dark:bg-coolgray-100 bg-white border-neutral-200 dark:border-coolgray-400">
                    <div class="text-xs font-bold text-neutral-500 uppercase tracking-wide pb-1">Current Plan</div>
                    <div class="text-xl font-bold dark:text-warning">
                        @if (data_get(currentTeam(), 'subscription')->type() == 'dynamic')
                            Pay-as-you-go
                        @else
                            {{ data_get(currentTeam(), 'subscription')->type() }}
                        @endif
                    </div>
                    <div class="pt-2 text-sm">
                        @if (currentTeam()->subscription->stripe_cancel_at_period_end)
                            <span class="text-red-500 font-medium">Cancelling at end of period</span>
                        @else
                            <span class="text-green-500 font-medium">Active</span>
                            <span class="text-neutral-500"> &middot; Invoice
                                {{ currentTeam()->subscription->stripe_invoice_paid ? 'paid' : 'not paid' }}</span>
                        @endif
                    </div>
                </div>

                {{-- Paid Servers Card --}}
                <div class="p-5 rounded border dark:bg-coolgray-100 bg-white border-neutral-200 dark:border-coolgray-400 cursor-pointer hover:border-warning/50 transition-colors"
                    @click="openAdjust()">
                    <div class="text-xs font-bold text-neutral-500 uppercase tracking-wide pb-1">Paid Servers</div>
                    <div class="text-xl font-bold dark:text-white" x-text="current"></div>
                    <div class="pt-2 text-sm text-neutral-500">Click to adjust</div>
                </div>

                {{-- Active Servers Card --}}
                <div
                    class="p-5 rounded border dark:bg-coolgray-100 bg-white border-neutral-200 dark:border-coolgray-400 {{ currentTeam()->serverOverflow() ? 'border-red-500 dark:border-red-500' : '' }}">
                    <div class="text-xs font-bold text-neutral-500 uppercase tracking-wide pb-1">Active Servers</div>
                    <div class="text-xl font-bold {{ currentTeam()->serverOverflow() ? 'text-red-500' : 'dark:text-white' }}">
                        {{ currentTeam()->servers->count() }}
                    </div>
                    <div class="pt-2 text-sm text-neutral-500">Currently running</div>
                </div>
            </div>

            @if (currentTeam()->serverOverflow())
                <x-callout type="danger" title="Server limit exceeded" class="mt-4">
                    You must delete {{ currentTeam()->servers->count() - $server_limits }} servers or upgrade your
                    subscription. Excess servers will be deactivated.
                </x-callout>
            @endif

            {{-- Adjust Server Limit Modal --}}
            <template x-teleport="body">
                <div x-show="showModal"
                    class="fixed top-0 left-0 z-99 flex items-center justify-center w-screen h-screen p-4" x-cloak>
                    <div x-show="showModal" class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"
                        @click="closeAdjust()">
                    </div>
                    <div x-show="showModal" x-trap.inert.noscroll="showModal"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="relative w-full border rounded-sm min-w-full lg:min-w-[36rem] max-w-[48rem] max-h-[calc(100vh-2rem)] bg-neutral-100 border-neutral-400 dark:bg-base dark:border-coolgray-300 flex flex-col">
                        <div class="flex justify-between items-center py-6 px-7 shrink-0">
                            <h3 class="pr-8 text-2xl font-bold">Adjust Server Limit</h3>
                            <button @click="closeAdjust()"
                                class="flex absolute top-2 right-2 justify-center items-center w-8 h-8 rounded-full dark:text-white hover:bg-coolgray-300">
                                <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <div class="relative w-auto overflow-y-auto px-7 pb-6 space-y-4"
                            style="-webkit-overflow-scrolling: touch;">
                            {{-- Server count input --}}
                            <div>
                                <label class="text-xs font-bold text-neutral-500 uppercase tracking-wide">Paid Servers</label>
                                <div class="flex items-center gap-3 pt-1">
                                    <input type="number" min="{{ $minServerLimit }}" max="{{ $maxServerLimit }}" step="1"
                                        x-model.number="qty"
                                        @input="preview = null"
                                        @change="qty = Math.min({{ $maxServerLimit }}, Math.max({{ $minServerLimit }}, qty || {{ $minServerLimit }}))"
                                        class="w-20 px-2 py-1 text-xl font-bold text-center rounded border dark:bg-coolgray-200 dark:border-coolgray-400 border-neutral-200 dark:text-white">
                                    <x-forms.button
                                        isHighlighted
                                        x-bind:disabled="!hasChanged || loading"
                                        @click="fetchPreview()">
                                        Calculate Price
                                    </x-forms.button>
                                </div>
                            </div>

                            {{-- Loading --}}
                            <div x-show="loading" x-cloak>
                                <x-loading text="Loading price preview..." />
                            </div>

                            {{-- Price Preview --}}
                            <div class="space-y-4" x-show="!loading && hasPreview" x-cloak>
                                <div>
                                    <div class="text-xs font-bold text-neutral-500 uppercase tracking-wide pb-1.5">Due now</div>
                                    <div class="flex justify-between gap-6 text-sm font-bold">
                                        <span class="dark:text-white">Prorated charge</span>
                                        <span class="dark:text-warning" x-text="fmt(preview.due_now)"></span>
                                    </div>
                                    <p class="text-xs text-neutral-500 pt-1">Charged immediately to your payment method.</p>
                                </div>
                                <div>
                                    <div class="text-xs font-bold text-neutral-500 uppercase tracking-wide pb-1.5">Next billing cycle</div>
                                    <div class="space-y-1.5">
                                        <div class="flex justify-between gap-6 text-sm">
                                            <span class="text-neutral-500" x-text="preview.quantity + ' servers × ' + fmt(preview.unit_price)"></span>
                                            <span class="dark:text-white" x-text="fmt(preview.recurring_subtotal)"></span>
                                        </div>
                                        <div class="flex justify-between gap-6 text-sm" x-show="preview?.tax_description" x-cloak>
                                            <span class="text-neutral-500" x-text="preview?.tax_description"></span>
                                            <span class="dark:text-white" x-text="fmt(preview?.recurring_tax)"></span>
                                        </div>
                                        <div class="flex justify-between gap-6 text-sm font-bold pt-1.5 border-t dark:border-coolgray-400 border-neutral-200">
                                            <span class="dark:text-white">Total / month</span>
                                            <span class="dark:text-white" x-text="fmt(preview.recurring_total)"></span>
                                        </div>
                                    </div>
                                </div>

                                {{-- Update Button with Confirmation --}}
                                <x-modal-confirmation
                                    title="Confirm Server Limit Update"
                                    buttonTitle="Update Server Limit"
                                    submitAction="updateQuantity"
                                    :confirmWithText="false"
                                    :confirmWithPassword="false"
                                    :actions="[
                                        'Your server limit will be updated immediately.',
                                        'The prorated amount will be invoiced and charged now.',
                                    ]"
                                    warningMessage="This will update your subscription and charge the prorated amount to your payment method."
                                    step2ButtonText="Confirm & Pay">
                                    <x-slot:content>
                                        <x-forms.button @click="$wire.set('quantity', qty)">
                                            Update Server Limit
                                        </x-forms.button>
                                    </x-slot:content>
                                </x-modal-confirmation>
                            </div>

                            {{-- Reduction Warning --}}
                            <div x-show="isReduction" x-cloak>
                                <x-callout type="danger" title="Warning">
                                    Reducing below your active server count will deactivate excess servers.
                                </x-callout>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </section>

        {{-- Billing, Refund & Cancellation --}}
        <section>
            <h3 class="pb-2">Manage Subscription</h3>
            <div class="flex flex-wrap items-center gap-2">
                {{-- Billing --}}
                <x-forms.button class="gap-2" wire:click='stripeCustomerPortal'>
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                        stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z" />
                    </svg>
                    Manage Billing on Stripe
                </x-forms.button>

                {{-- Resume or Cancel --}}
                @if (currentTeam()->subscription->stripe_cancel_at_period_end)
                    <x-forms.button wire:click="resumeSubscription">Resume Subscription</x-forms.button>
                @else
                    <x-modal-confirmation title="Cancel at End of Billing Period?"
                        buttonTitle="Cancel at Period End" submitAction="cancelAtPeriodEnd"
                        :actions="[
                            'Your subscription will remain active until the end of the current billing period.',
                            'No further charges will be made after the current period.',
                            'You can resubscribe at any time.',
                        ]" confirmationText="{{ currentTeam()->name }}"
                        confirmationLabel="Enter your team name to confirm"
                        shortConfirmationLabel="Team Name" step2ButtonText="Confirm Cancellation" />
                    <x-modal-confirmation title="Cancel Immediately?" buttonTitle="Cancel Immediately"
                        isErrorButton submitAction="cancelImmediately"
                        :actions="[
                            'Your subscription will be cancelled immediately.',
                            'All servers will be deactivated.',
                            'No refund will be issued for the remaining period.',
                        ]" confirmationText="{{ currentTeam()->name }}"
                        confirmationLabel="Enter your team name to confirm"
                        shortConfirmationLabel="Team Name" step2ButtonText="Permanently Cancel" />
                @endif

                {{-- Refund --}}
                @if ($refundCheckLoading)
                    <x-loading text="Checking refund..." />
                @elseif ($isRefundEligible && !currentTeam()->subscription->stripe_cancel_at_period_end)
                    <x-modal-confirmation title="Request Full Refund?" buttonTitle="Request Full Refund"
                        isErrorButton submitAction="refundSubscription"
                        :actions="[
                            'Your latest payment will be fully refunded.',
                            'Your subscription will be cancelled immediately.',
                            'All servers will be deactivated.',
                        ]" confirmationText="{{ currentTeam()->name }}"
                        confirmationLabel="Enter your team name to confirm" shortConfirmationLabel="Team Name"
                        step2ButtonText="Confirm Refund & Cancel" />
                @endif
            </div>

            {{-- Contextual notes --}}
            @if ($isRefundEligible && !currentTeam()->subscription->stripe_cancel_at_period_end)
                <p class="mt-2 text-sm text-neutral-500">Eligible for a full refund &mdash; <strong class="dark:text-warning">{{ $refundDaysRemaining }}</strong> days remaining.</p>
            @elseif ($refundAlreadyUsed)
                <p class="mt-2 text-sm text-neutral-500">Refund already processed. Each team is eligible for one refund only.</p>
            @endif
            @if (currentTeam()->subscription->stripe_cancel_at_period_end)
                <p class="mt-2 text-sm text-neutral-500">Your subscription is set to cancel at the end of the billing period.</p>
            @endif
        </section>

        <div class="text-sm text-neutral-500">
            Need help? <a class="underline dark:text-white" href="{{ config('constants.urls.contact') }}"
                target="_blank">Contact us.</a>
        </div>
    @endif
</div>
