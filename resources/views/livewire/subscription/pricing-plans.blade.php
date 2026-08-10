<div x-data="{ selected: 'monthly' }" class="w-full">
    <x-application.settings-section title="Pay as you go"
        description="Dynamic pricing based on the number of servers connected to your team.">
        <x-slot:actions>
            <div
                class="flex h-8 items-center rounded-lg border border-neutral-200 bg-neutral-100 p-0.5 dark:border-white/[0.08] dark:bg-white/[0.035]">
                <button type="button" x-on:click="selected = 'monthly'"
                    class="app-tab h-6! px-2.5!"
                    :class="selected === 'monthly'
                        ? 'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25'
                        : ''">
                    Monthly
                </button>
                <button type="button" x-on:click="selected = 'yearly'"
                    class="app-tab h-6! px-2.5!"
                    :class="selected === 'yearly'
                        ? 'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25'
                        : ''">
                    Yearly
                </button>
            </div>
        </x-slot:actions>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)]">
            <div
                class="rounded-[10px] border border-neutral-200 bg-neutral-50 p-4 dark:border-white/[0.08] dark:bg-white/[0.025]">
                <p class="text-[11px] font-medium text-neutral-500 dark:text-fg-faint">Base price</p>
                <div class="mt-2 flex items-end gap-1.5">
                    <span x-show="selected === 'monthly'" x-cloak
                        class="text-2xl font-semibold tracking-tight">$5</span>
                    <span x-show="selected === 'yearly'" x-cloak
                        class="text-2xl font-semibold tracking-tight">$4</span>
                    <span class="pb-0.5 text-[12px] text-neutral-500 dark:text-fg-dim">per month</span>
                </div>
                <p class="mt-2 text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                    <span x-show="selected === 'monthly'" x-cloak>$3 per additional server, billed monthly.</span>
                    <span x-show="selected === 'yearly'" x-cloak>$2.70 per additional server, billed annually.</span>
                </p>

                <div class="mt-4">
                    <x-forms.button x-show="selected === 'monthly'" x-cloak class="w-full justify-center"
                        wire:click="subscribeStripe('dynamic-monthly')" isHighlighted>
                        Subscribe monthly
                    </x-forms.button>
                    <x-forms.button x-show="selected === 'yearly'" x-cloak class="w-full justify-center"
                        wire:click="subscribeStripe('dynamic-yearly')" isHighlighted>
                        Subscribe yearly
                    </x-forms.button>
                </div>
            </div>

            <div class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                @foreach ([
                    'Connect unlimited servers',
                    'Deploy unlimited resources per server',
                    'Transactional email notifications',
                    'Support by email',
                    'All upcoming platform features',
                ] as $feature)
                    <div class="flex min-h-10 items-center gap-2.5 py-2 text-[12px]">
                        <x-reicon name="check-circle" class="size-4 shrink-0 text-emerald-500" />
                        <span>{{ $feature }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <p class="mt-5 text-[11px] leading-5 text-neutral-500 dark:text-fg-faint">
            Bring your own servers from any cloud provider or supported Linux machine. Prices exclude applicable
            taxes.
        </p>
    </x-application.settings-section>
</div>
