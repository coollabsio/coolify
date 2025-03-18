<div x-data="{ selected: 'monthly' }" class="w-full pb-20 pt-10">
    <div class="px-6 mx-auto lg:px-8">
        <div class="flex justify-center">
            <fieldset
                class="grid grid-cols-2 p-1 text-xs font-semibold leading-5 text-center rounded dark:text-white gap-x-1 dark:bg-white/5 bg-black/5">
                <legend class="sr-only">Payment frequency</legend>
                <label class="cursor-pointer rounded px-2.5 py-1"
                    :class="selected === 'monthly' ? 'dark:bg-coollabs-100 bg-warning dark:text-white' : ''">
                    <input type="radio" x-on:click="selected = 'monthly'" name="frequency" value="monthly"
                        class="sr-only">
                    <span>Monthly</span>
                </label>
                <label class="cursor-pointer rounded px-2.5 py-1"
                    :class="selected === 'yearly' ? 'dark:bg-coollabs-100 bg-warning dark:text-white' : ''">
                    <input type="radio" x-on:click="selected = 'yearly'" name="frequency" value="annually"
                        class="sr-only">
                    <span>Annually <span class="text-xs dark:text-warning text-coollabs">(save ~20%)</span></span>
                </label>
            </fieldset>
        </div>
        <div class="flow-root mt-12">
            <div
                class="grid max-w-sm grid-cols-1 -mt-16 divide-y divide-neutral-200 dark:divide-coolgray-500 isolate gap-y-16 sm:mx-auto lg:-mx-8 lg:mt-0 lg:max-w-none lg:grid-cols-1 lg:divide-x lg:divide-y-0 xl:-mx-4">
                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-14">
                    <h3 id="tier-dynamic" class="text-4xl font-semibold leading-7 dark:text-white">Pay-as-you-go</h3>
                    <p class="mt-4 text-sm leading-6 dark:text-neutral-400">
                        Dynamic pricing based on the number of servers you connect.
                    </p>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">$5</span>
                            <span class="text-sm font-semibold leading-6 "> base price</span>
                        </span>

                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">$4</span>
                            <span class="text-sm font-semibold leading-6 "> base price</span>
                        </span>
                    </p>
                    <p class="flex items-baseline mb-4 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-base font-semibold tracking-tight dark:text-white">$3</span>
                            <span class="text-sm font-semibold leading-6 "> per additional servers <span
                                    class="font-normal dark:text-white">billed monthly (+VAT)</span></span>
                        </span>

                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-base font-semibold tracking-tight dark:text-white">$2.7</span>
                            <span class="text-sm font-semibold leading-6 "> per additional servers <span
                                    class="font-normal dark:text-white">billed annually (+VAT)</span></span>
                        </span>
                    </p>
                    <div class="flex items-center pt-6">
                        <svg xmlns="http://www.w3.org/2000/svg" class="flex-none w-8 h-8 mr-3 text-warning"
                            fill="currentColor" viewBox="0 0 256 256">
                            <path
                                d="M236.8,188.09,149.35,36.22h0a24.76,24.76,0,0,0-42.7,0L19.2,188.09a23.51,23.51,0,0,0,0,23.72A24.35,24.35,0,0,0,40.55,224h174.9a24.35,24.35,0,0,0,21.33-12.19A23.51,23.51,0,0,0,236.8,188.09ZM222.93,203.8a8.5,8.5,0,0,1-7.48,4.2H40.55a8.5,8.5,0,0,1-7.48-4.2,7.59,7.59,0,0,1,0-7.72L120.52,44.21a8.75,8.75,0,0,1,15,0l87.45,151.87A7.59,7.59,0,0,1,222.93,203.8ZM120,144V104a8,8,0,0,1,16,0v40a8,8,0,0,1-16,0Zm20,36a12,12,0,1,1-12-12A12,12,0,0,1,140,180Z">
                            </path>
                        </svg>

                        <div class="flex flex-col text-sm dark:text-white">
                            <div>
                                You need to bring your own servers from any cloud provider (such as <a class="underline"
                                    href="https://coolify.io/hetzner" target="_blank">Hetzner</a>, DigitalOcean, AWS,
                                etc.)
                            </div>
                            <div>
                                (You can connect your RPi, old laptop, or any other device that runs
                                the <a class="underline"
                                    href="https://coolify.io/docs/installation#supported-operating-systems"
                                    target="_blank">supported operating systems</a>.)
                            </div>
                        </div>
                    </div>
                    <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-basic"
                        class="w-full h-10 buyme" wire:click="subscribeStripe('dynamic-monthly')">
                        Subscribe
                    </x-forms.button>
                    <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-basic"
                        class="w-full h-10 buyme" wire:click="subscribeStripe('dynamic-yearly')">
                        Subscribe
                    </x-forms.button>
                    <ul role="list" class="mt-8 space-y-3 text-sm leading-6 dark:text-neutral-400">
                        <li class="flex">
                            <svg class="flex-none w-5 h-6 mr-3 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Connect
                            <span class="px-1 font-bold dark:text-white">unlimited</span> servers
                        </li>
                        <li class="flex">
                            <svg class="flex-none w-5 h-6 mr-3 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Deploy
                            <span class="px-1 font-bold dark:text-white">unlimited</span> applications per server
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Free email notifications
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Support by email
                        </li>
                        <li class="flex font-bold dark:text-white gap-x-3">
                            <svg width="512" height="512" class="flex-none w-5 h-6 text-green-500"
                                viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2">
                                    <path
                                        d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3-5a9 9 0 0 0 6-8a3 3 0 0 0-3-3a9 9 0 0 0-8 6a6 6 0 0 0-5 3" />
                                    <path d="M7 14a6 6 0 0 0-3 6a6 6 0 0 0 6-3m4-8a1 1 0 1 0 2 0a1 1 0 1 0-2 0" />
                                </g>
                            </svg>
                            + All Upcoming Features
                        </li>
                        <li class="flex dark:text-white gap-x-3">
                            <svg xmlns="http://www.w3.org/2000/svg" class="flex-none w-5 h-6 text-green-500"
                                viewBox="0 0 256 256">
                                <rect width="256" height="256" fill="none" />
                                <polyline points="32 136 72 136 88 112 120 160 136 136 160 136" fill="none"
                                    stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="16" />
                                <path
                                    d="M24,104c0-.67,0-1.33,0-2A54,54,0,0,1,78,48c22.59,0,41.94,12.31,50,32,8.06-19.69,27.41-32,50-32a54,54,0,0,1,54,54c0,66-104,122-104,122s-42-22.6-72.58-56"
                                    fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="16" />
                            </svg>

                            Do you require official support for your self-hosted instance?<a class="underline"
                                href="https://coolify.io/docs/contact">Contact Us</a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
