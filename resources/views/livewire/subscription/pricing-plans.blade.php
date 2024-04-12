<div x-data="{ selected: 'monthly' }" class="w-full pb-20">
    <div class="max-w-2xl px-6 mx-auto lg:px-8">
        <div class="flex justify-center">
            <fieldset
                class="grid grid-cols-2 p-1 text-xs font-semibold leading-5 text-center rounded dark:text-white gap-x-1 bg-white/5">
                <legend class="sr-only">Payment frequency</legend>
                <label class="cursor-pointer rounded px-2.5 py-1"
                    :class="selected === 'monthly' ? 'bg-coollabs-100 text-white' : ''">
                    <input type="radio" x-on:click="selected = 'monthly'" name="frequency" value="monthly"
                        class="sr-only">
                    <span>Monthly</span>
                </label>
                <label class="cursor-pointer rounded px-2.5 py-1"
                    :class="selected === 'yearly' ? 'bg-coollabs-100 text-white' : ''">
                    <input type="radio" x-on:click="selected = 'yearly'" name="frequency" value="annually"
                        class="sr-only">
                    <span>Annually</span>
                </label>
            </fieldset>
        </div>
        <div x-show="selected === 'monthly'" class="flex justify-center h-10 mt-3 text-sm leading-6 ">
            <div>Save <span class="font-bold text-black dark:text-warning">10%</span> annually with the yearly plans.
            </div>
        </div>
        <div x-show="selected === 'yearly'" class="flex justify-center h-10 mt-3 text-sm leading-6 ">
            <div>
            </div>
        </div>
        <div class="flow-root mt-12">
            <div class="pb-10 text-xl text-center">For the detailed list of features, please visit our landing page: <a
                    class="font-bold underline dark:text-white" href="https://coolify.io">coolify.io</a></div>
            <div
                class="grid max-w-sm grid-cols-1 -mt-16 divide-y divide-neutral-200 dark:divide-coolgray-500 isolate gap-y-16 sm:mx-auto lg:-mx-8 lg:mt-0 lg:max-w-none lg:grid-cols-1 lg:divide-x lg:divide-y-0 xl:-mx-4">
                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-14">
                    <h3 id="tier-dynamic" class="text-4xl font-semibold leading-7 dark:text-white">Dynamic</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">$5</span>
                            <span class="text-sm font-semibold leading-6 "> for the first 2 servers</span>
                        </span>

                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">$4</span>
                            <span class="text-sm font-semibold leading-6 ">/month + VAT</span>
                        </span>
                    </p>
                    <p class="flex items-baseline gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-2xl font-bold tracking-tight dark:text-white">$3</span>
                            <span class="text-sm font-semibold leading-6 "> for any additional</span>
                        </span>

                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">$4</span>
                            <span class="text-sm font-semibold leading-6 ">/month + VAT</span>
                        </span>
                    </p>
                    <span x-show="selected === 'monthly'" x-cloak>
                        <span>billed monthly (+VAT)</span>
                    </span>
                    <span x-show="selected === 'yearly'" x-cloak>
                        <span>billed annually</span>
                    </span>
                    <x-forms.button x-show="selected === 'monthly'" x-cloak aria-describedby="tier-basic"
                        class="w-full h-10 buyme" wire:click="subscribeStripe('dynamic-monthly')">
                        {{ $isTrial ? 'Start Trial' : 'Subscribe' }}
                    </x-forms.button>
                    <x-forms.button x-show="selected === 'yearly'" x-cloak aria-describedby="tier-basic"
                        class="w-full h-10 buyme" wire:click="subscribeStripe('dynamic-yearly')">
                        {{ $isTrial ? 'Start Trial' : 'Subscribe' }}
                    </x-forms.button>
                    <p class="mt-10 text-sm leading-6 dark:text-white h-[6.5rem]">Begin hosting your own services in the
                        cloud.
                    </p>
                    <ul role="list" class="space-y-3 text-sm leading-6 ">
                        <li class="flex">
                            <svg class="flex-none w-5 h-6 mr-3 dark:text-warning" viewBox="0 0 20 20"
                                fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.775 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Connect <span class="px-1 font-bold dark:text-white">2</span> servers
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 dark:text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Included Email System
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 dark:text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Email Support
                        </li>
                        <li class="flex font-bold dark:text-white gap-x-3">
                            <svg width="512" height="512" class="flex-none w-5 h-6 text-green-600"
                                viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                    stroke-width="2">
                                    <path
                                        d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3-5a9 9 0 0 0 6-8a3 3 0 0 0-3-3a9 9 0 0 0-8 6a6 6 0 0 0-5 3" />
                                    <path d="M7 14a6 6 0 0 0-3 6a6 6 0 0 0 6-3m4-8a1 1 0 1 0 2 0a1 1 0 1 0-2 0" />
                                </g>
                            </svg>
                            + All upcoming features
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
