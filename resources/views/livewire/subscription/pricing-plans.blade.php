<div x-data="{ selected: 'monthly' }" class="w-full">
    {{-- Frequency Toggle --}}
    <div class="flex justify-center mb-8">
        <fieldset
            class="grid grid-cols-2 p-1 text-xs font-semibold leading-5 text-center rounded-sm dark:text-white gap-x-1 dark:bg-white/5 bg-black/5">
            <legend class="sr-only">Payment frequency</legend>
            <label
                :class="selected === 'monthly' ?
                    'dark:bg-coollabs-100 bg-warning dark:text-white cursor-pointer rounded-sm px-2.5 py-1' :
                    'cursor-pointer rounded-sm px-2.5 py-1'">
                <input type="radio" x-on:click="selected = 'monthly'" name="frequency" value="monthly"
                    class="sr-only">
                <span :class="selected === 'monthly' ? 'dark:text-white' : ''">Monthly</span>
            </label>
            <label
                :class="selected === 'yearly' ?
                    'dark:bg-coollabs-100 bg-warning dark:text-white cursor-pointer rounded-sm px-2.5 py-1' :
                    'cursor-pointer rounded-sm px-2.5 py-1'">
                <input type="radio" x-on:click="selected = 'yearly'" name="frequency" value="annually"
                    class="sr-only">
                <span :class="selected === 'yearly' ? 'dark:text-white' : ''">Annually <span
                        class="text-xs dark:text-warning text-coollabs">(save ~20%)</span></span>
            </label>
        </fieldset>
    </div>

    <div class="max-w-xl mx-auto">
        {{-- Plan Header + Pricing --}}
        <h3 id="tier-dynamic" class="text-2xl font-bold dark:text-white">Pay-as-you-go</h3>
        <p class="mt-1 text-sm dark:text-neutral-400">Dynamic pricing based on the number of servers you connect.</p>

        <div class="mt-4 flex items-baseline gap-x-1">
            <span x-show="selected === 'monthly'" x-cloak>
                <span class="text-4xl font-bold tracking-tight dark:text-white">$5</span>
                <span class="text-sm dark:text-neutral-400">/ mo base</span>
            </span>
            <span x-show="selected === 'yearly'" x-cloak>
                <span class="text-4xl font-bold tracking-tight dark:text-white">$4</span>
                <span class="text-sm dark:text-neutral-400">/ mo base</span>
            </span>
        </div>
        <p class="mt-1 text-sm dark:text-neutral-400">
            <span x-show="selected === 'monthly'" x-cloak>
                + <span class="font-semibold dark:text-white">$3</span> per additional server, billed monthly (+VAT)
            </span>
            <span x-show="selected === 'yearly'" x-cloak>
                + <span class="font-semibold dark:text-white">$2.7</span> per additional server, billed annually (+VAT)
            </span>
        </p>

        {{-- Subscribe Button --}}
        <div class="flex mt-6">
            <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-dynamic"
                class="w-full" wire:click="subscribeStripe('dynamic-monthly')">
                Subscribe
            </x-forms.button>
            <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-dynamic"
                class="w-full" wire:click="subscribeStripe('dynamic-yearly')">
                Subscribe
            </x-forms.button>
        </div>

        {{-- Features --}}
        <div class="mt-8 pt-6 border-t dark:border-coolgray-400 border-neutral-200">
            <ul role="list" class="space-y-2.5 text-sm">
                <li class="flex items-center gap-2.5">
                    <svg class="w-4 h-4 shrink-0 dark:text-warning" viewBox="0 0 20 20" fill="currentColor"
                        aria-hidden="true">
                        <path fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                            clip-rule="evenodd" />
                    </svg>
                    <span class="dark:text-neutral-300">Connect <span
                            class="font-bold dark:text-white">unlimited</span> servers</span>
                </li>
                <li class="flex items-center gap-2.5">
                    <svg class="w-4 h-4 shrink-0 dark:text-warning" viewBox="0 0 20 20" fill="currentColor"
                        aria-hidden="true">
                        <path fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                            clip-rule="evenodd" />
                    </svg>
                    <span class="dark:text-neutral-300">Deploy <span
                            class="font-bold dark:text-white">unlimited</span> applications per server</span>
                </li>
                <li class="flex items-center gap-2.5">
                    <svg class="w-4 h-4 shrink-0 dark:text-warning" viewBox="0 0 20 20" fill="currentColor"
                        aria-hidden="true">
                        <path fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                            clip-rule="evenodd" />
                    </svg>
                    <span class="dark:text-neutral-300">Free email notifications</span>
                </li>
                <li class="flex items-center gap-2.5">
                    <svg class="w-4 h-4 shrink-0 dark:text-warning" viewBox="0 0 20 20" fill="currentColor"
                        aria-hidden="true">
                        <path fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                            clip-rule="evenodd" />
                    </svg>
                    <span class="dark:text-neutral-300">Support by email</span>
                </li>
                <li class="flex items-center gap-2.5 font-bold dark:text-white">
                    <svg class="w-4 h-4 shrink-0 text-green-500" viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor"
                        stroke-linecap="round" stroke-linejoin="round" stroke-width="2">
                        <path
                            d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3-5a9 9 0 0 0 6-8a3 3 0 0 0-3-3a9 9 0 0 0-8 6a6 6 0 0 0-5 3" />
                        <path d="M7 14a6 6 0 0 0-3 6a6 6 0 0 0 6-3m4-8a1 1 0 1 0 2 0a1 1 0 1 0-2 0" />
                    </svg>
                    + All Upcoming Features
                </li>
            </ul>
        </div>

        {{-- BYOS Notice + Support --}}
        <div class="mt-6 pt-6 border-t dark:border-coolgray-400 border-neutral-200 text-sm dark:text-neutral-400">
            <p>You need to bring your own servers from any cloud provider (<a class="underline" href="https://coolify.io/hetzner" target="_blank">Hetzner</a>, DigitalOcean, AWS, etc.) or connect any device running a <a class="underline" href="https://coolify.io/docs/installation#supported-operating-systems" target="_blank">supported OS</a>.</p>
            <p class="mt-3">Need official support for your self-hosted instance? <a class="underline dark:text-white" href="https://coolify.io/docs/contact" target="_blank">Contact Us</a></p>
        </div>
    </div>
</div>
