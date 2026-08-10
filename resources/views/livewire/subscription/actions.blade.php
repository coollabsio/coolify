<div wire:init="loadRefundEligibility" class="application-settings-workspace flex flex-col gap-6">
    @if (subscriptionProvider() === 'stripe')
        {{-- Plan Overview --}}
        <x-application.settings-section title="Plan overview"
            description="Review billing status and the number of servers included in this plan." x-data="{
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
            @keydown.escape.window="if (showModal) { closeAdjust(); }">
            <div class="space-y-2">
                <div class="text-sm">
                    <span class="text-neutral-500">Plan:</span>
                    <span class="dark:text-warning font-medium">
                        @if (data_get(currentTeam(), 'subscription')->type() == 'dynamic')
                            Pay-as-you-go
                        @else
                            {{ data_get(currentTeam(), 'subscription')->type() }}
                        @endif
                    </span>
                    <span class="text-neutral-500">&middot; {{ $billingInterval === 'yearly' ? 'Yearly' : 'Monthly' }}</span>
                    <span class="text-neutral-500">&middot;</span>
                    @if (currentTeam()->subscription->stripe_cancel_at_period_end)
                        <span class="text-red-500 font-medium">Cancelling at end of period</span>
                    @else
                        <span class="text-green-500 font-medium">Active</span>
                    @endif
                </div>
                <div class="text-sm flex items-center gap-2 flex-wrap">
                    <span>
                        <span class="text-neutral-500">Active servers:</span>
                        <span class="font-medium {{ currentTeam()->serverOverflow() ? 'text-red-500' : 'dark:text-white' }}">{{ currentTeam()->servers->count() }}</span>
                        <span class="text-neutral-500">/</span>
                        <span class="font-medium dark:text-white" x-text="current"></span>
                        <span class="text-neutral-500">paid</span>
                    </span>
                    <x-forms.button isHighlighted @click="openAdjust()">Adjust</x-forms.button>
                </div>
                <div class="text-sm text-neutral-500">
                    @if ($refundCheckLoading)
                        <x-loading text="Loading..." />
                    @elseif ($nextBillingDate)
                        @if (currentTeam()->subscription->stripe_cancel_at_period_end)
                            Cancels on <span class="dark:text-white font-medium">{{ $nextBillingDate }}</span>
                        @else
                            Next billing <span class="dark:text-white font-medium">{{ $nextBillingDate }}</span>
                        @endif
                    @endif
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
                        class="application-settings-section application-settings-form relative flex max-h-[calc(100vh-2rem)] w-full max-w-xl flex-col shadow-modal">
                        <div class="flex min-h-11 shrink-0 items-center justify-between px-4">
                            <h3 class="text-[13px]! font-semibold!">Adjust server limit</h3>
                            <button type="button" @click="closeAdjust()"
                                class="flex size-7 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                <x-reicon name="x" class="size-3.5" />
                            </button>
                        </div>
                        <div class="application-settings-section-body relative w-auto space-y-4 overflow-y-auto p-4"
                            style="-webkit-overflow-scrolling: touch;">
                            {{-- Server count input --}}
                            <div>
                                <label class="mb-1.5 block">Paid servers</label>
                                <div class="flex items-center gap-3">
                                    <input type="number" min="{{ $minServerLimit }}" max="{{ $maxServerLimit }}" step="1"
                                        x-model.number="qty"
                                        @input="preview = null"
                                        @change="qty = Math.min({{ $maxServerLimit }}, Math.max({{ $minServerLimit }}, qty || {{ $minServerLimit }}))"
                                        class="h-8! w-24 rounded-lg! border-neutral-200! bg-white! px-2! py-0! text-center! text-[13px]! font-semibold! shadow-none! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.04]! dark:text-fg!">
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
                                        <span class="dark:text-warning" x-text="fmt(preview?.due_now)"></span>
                                    </div>
                                    <p class="text-xs text-neutral-500 pt-1">Charged immediately to your payment method.</p>
                                </div>
                                <div>
                                    <div class="text-xs font-bold text-neutral-500 uppercase tracking-wide pb-1.5">
                                        Next billing cycle
                                        @if ($nextBillingDate)
                                            <span class="normal-case font-normal">&middot; {{ $nextBillingDate }}</span>
                                        @endif
                                    </div>
                                    <div class="space-y-1.5">
                                        <div class="flex justify-between gap-6 text-sm">
                                            <span class="text-neutral-500" x-text="preview?.quantity + ' servers × ' + fmt(preview?.unit_price)"></span>
                                            <span class="dark:text-white" x-text="fmt(preview?.recurring_subtotal)"></span>
                                        </div>
                                        <div class="flex justify-between gap-6 text-sm" x-show="preview?.tax_description" x-cloak>
                                            <span class="text-neutral-500" x-text="preview?.tax_description"></span>
                                            <span class="dark:text-white" x-text="fmt(preview?.recurring_tax)"></span>
                                        </div>
                                        <div class="flex justify-between gap-6 text-sm font-bold pt-1.5 border-t dark:border-coolgray-400 border-neutral-200">
                                            <span class="dark:text-white">Total / <span x-text="preview?.billing_interval === 'year' ? 'year' : 'month'">month</span></span>
                                            <span class="dark:text-white" x-text="fmt(preview?.recurring_total)"></span>
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
                                        <x-forms.button class="w-full" @click="$wire.set('quantity', qty)">
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
        </x-application.settings-section>

        {{-- Manage Subscription --}}
        <x-application.settings-section title="Billing"
            description="Open the hosted billing portal to update invoices and payment methods.">
            <div class="flex flex-wrap items-center gap-2">
                <x-forms.button class="gap-2" wire:click='stripeCustomerPortal'>
                    <x-reicon name="subscription" class="size-3.5" />
                    Open billing portal
                </x-forms.button>
            </div>
        </x-application.settings-section>

        {{-- Cancel Subscription --}}
        <x-application.settings-section title="Cancellation"
            description="End the plan at the next billing date or cancel it immediately.">
            <div class="flex flex-wrap items-center gap-2">
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
                    @if ($isRefundEligible)
                        <div wire:key="cancel-immediately-refundable">
                            <x-modal-confirmation title="Cancel Immediately?" buttonTitle="Cancel Immediately"
                                isErrorButton submitAction="cancelImmediately"
                                :checkboxes="[
                                    [
                                        'id' => 'refundLatestPayment',
                                        'label' => 'Refund my latest payment (eligible for '.$refundDaysRemaining.' more days).',
                                        'default_warning' => 'No refund will be issued for the remaining period.',
                                    ],
                                ]"
                                :actions="[
                                    'Your subscription will be cancelled immediately.',
                                    'All servers will be deactivated.',
                                ]" confirmationText="{{ currentTeam()->name }}"
                                confirmationLabel="Enter your team name to confirm"
                                shortConfirmationLabel="Team Name" step2ButtonText="Permanently Cancel" />
                        </div>
                    @else
                        <div wire:key="cancel-immediately-standard">
                            <x-modal-confirmation title="Cancel Immediately?" buttonTitle="Cancel Immediately"
                                isErrorButton submitAction="cancelImmediately"
                                :actions="[
                                    'Your subscription will be cancelled immediately.',
                                    'All servers will be deactivated.',
                                    'No refund will be issued for the remaining period.',
                                ]" confirmationText="{{ currentTeam()->name }}"
                                confirmationLabel="Enter your team name to confirm"
                                shortConfirmationLabel="Team Name" step2ButtonText="Permanently Cancel" />
                        </div>
                    @endif
                @endif
            </div>
            @if (currentTeam()->subscription->stripe_cancel_at_period_end)
                <p class="mt-2 text-sm text-neutral-500">Your subscription is set to cancel at the end of the billing period.</p>
            @endif
        </x-application.settings-section>

        {{-- Refund --}}
        <x-application.settings-section title="Refund"
            description="Request a refund when this team is still inside the eligibility window.">
            @if ($refundCheckLoading || $isRefundEligible)
                <div class="flex flex-wrap items-center gap-2">
                    @if ($refundCheckLoading)
                        <x-forms.button disabled>Request Full Refund</x-forms.button>
                    @else
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
            @endif
            <p class="mt-2 text-sm text-neutral-500">
                @if ($refundCheckLoading)
                    Checking refund eligibility...
                @elseif ($isRefundEligible)
                    Eligible for a full refund &mdash; <strong class="dark:text-warning">{{ $refundDaysRemaining }}</strong> days remaining.
                @elseif ($refundAlreadyUsed)
                    Refund already processed. Each team is eligible for one refund only.
                @else
                    Not eligible for a refund.
                @endif
            </p>
        </x-application.settings-section>

        <div class="text-sm text-neutral-500">
            Need help? <a class="underline dark:text-white" href="{{ config('constants.urls.contact') }}"
                target="_blank">Contact us.</a>
        </div>
    @endif
</div>
