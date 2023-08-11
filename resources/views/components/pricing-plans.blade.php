<div x-data="{ selected: 'monthly' }" class="w-full">
    <div class="px-6 mx-auto lg:px-8">
        <div class="flex justify-center mt-5">
            <fieldset
                class="grid grid-cols-2 p-1 text-xs font-semibold leading-5 text-center rounded-full gap-x-1 ring-1 ring-inset ring-coolgray-500">
                <legend class="sr-only">Payment frequency</legend>
                <label class="cursor-pointer rounded-full px-2.5 py-1"
                    :class="selected === 'monthly' ? 'bg-coollabs-100 text-white' : ''">
                    <input type="radio" x-on:click="selected = 'monthly'" name="frequency" value="monthly"
                        class="sr-only">
                    <span>Monthly</span>
                </label>
                <label class="cursor-pointer rounded-full px-2.5 py-1"
                    :class="selected === 'yearly' ? 'bg-coollabs-100 text-white' : ''">
                    <input type="radio" x-on:click="selected = 'yearly'" name="frequency" value="annually"
                        class="sr-only">
                    <span>Annually</span>
                </label>
            </fieldset>
        </div>
        <div x-show="selected === 'monthly'" class="flex justify-center h-10 mt-3 text-sm leading-6 ">
            <div>Save <span class="text-2xl font-bold text-warning">20%</span> with the
                yearly plan
            </div>
        </div>
        <div x-show="selected === 'yearly'" class="flex justify-center h-10 mt-3 text-sm leading-6 ">
            <div>Congratulations! 🎉 You are saving money with this choice!
            </div>
        </div>
        <div class="flow-root mt-12">
            <div
                class="grid max-w-sm grid-cols-1 -mt-16 divide-y divide-coolgray-500 isolate gap-y-16 sm:mx-auto lg:-mx-8 lg:mt-0 lg:max-w-none lg:grid-cols-4 lg:divide-x lg:divide-y-0 xl:-mx-4">
                <div class="px-8 pt-16 lg:pt-0">
                    <h3 id="tier-basic" class="text-base font-semibold leading-7 text-white">Unlimited Trial</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-5xl font-bold tracking-tight text-white">Free</span>
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-5xl font-bold tracking-tight text-white">Still Free </span>
                        </span>
                    </p>
                    <a href="https://github.com/coollabsio/coolify" aria-describedby="tier-basic" class="buyme">Get
                        Started</a>
                    <p class="pb-6 mt-10 text-sm leading-6 text-white">Start self-hosting without limits with our OSS
                        version.</p>
                    <ul role="list" class="space-y-3 text-sm leading-6 ">
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Same features as the paid version
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            You need to take care of everything
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            If you brave enough, you can do it!
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Community Support
                        </li>
                    </ul>
                </div>
                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-14">
                    <h3 id="tier-basic" class="text-base font-semibold leading-7 text-white">Basic</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-5xl font-bold tracking-tight text-white">$15</span>
                            <span class="text-sm font-semibold leading-6 ">/monthly</span>
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-5xl font-bold tracking-tight text-white">$144</span>
                            <span class="text-sm font-semibold leading-6 ">/yearly</span>
                        </span>
                    </p>
                    <a href="{{ getSubscriptionLink(1) }}" aria-describedby="tier-basic" class="buyme">Subscribe</a>
                    <p class="pb-6 mt-10 text-sm leading-6 text-white">Start self-hosting in the cloud with a single
                        server.
                    </p>
                    <ul role="list" class="space-y-3 text-sm leading-6 ">
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            1 server
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Unlimited Deployments
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            30 days of backups
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Basic Support
                        </li>
                    </ul>
                </div>
                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-14">
                    <h3 id="tier-essential" class="text-base font-semibold leading-7 text-white">Essential</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-5xl font-bold tracking-tight text-white">$30</span>
                            <span class="text-sm font-semibold leading-6 ">/monthly</span>
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-5xl font-bold tracking-tight text-white">$288</span>
                            <span class="text-sm font-semibold leading-6 ">/yearly</span>
                        </span>
                    </p>
                    <a href="{{ getSubscriptionLink(2) }}" aria-describedby="tier-essential"
                        class="buyme">Subscribe</a>
                    <p class="mt-10 text-sm leading-6 text-white">Scale your business or self-hosting environment.</p>
                    <ul role="list" class="mt-6 space-y-3 text-sm leading-6 ">
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            5 servers
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Unlimited Deployments
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            30 days of backups
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Basic Support
                        </li>
                    </ul>
                </div>
                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-14">
                    <h3 id="tier-growth" class="text-base font-semibold leading-7 text-white">Growth</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-5xl font-bold tracking-tight text-white">$60</span>
                            <span class="text-sm font-semibold leading-6 ">/monthly</span>
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-5xl font-bold tracking-tight text-white">$576</span>
                            <span class="text-sm font-semibold leading-6 ">/yearly</span>
                        </span>
                    </p>

                    <a href="{{ getSubscriptionLink(3) }}" aria-describedby="tier-growth"
                        class="buyme">Subscribe</a>
                    <p class="mt-10 text-sm leading-6 text-white">Deploy complex infrastuctures and
                        manage them easily in one place.</p>
                    <ul role="list" class="mt-6 space-y-3 text-sm leading-6 ">
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Unlimited servers
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Unlimited deployments
                        </li>
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            30 days of backups
                        </li>
                        <li class="flex font-bold text-white gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Priority Support
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
