@props([
    'showSubscribeButtons' => true,
])
<div x-data="{ selected: 'yearly' }" class="w-full pb-20">
    <div class="px-6 mx-auto lg:px-8">
        <div class="flex justify-center mt-5">
            <fieldset
                class="grid grid-cols-2 p-1 text-xs font-semibold leading-5 text-center rounded-full gap-x-1 ">
                <legend class="sr-only">Payment frequency</legend>
                <label class="cursor-pointer  rounded px-2.5 py-1"
                    :class="selected === 'monthly' ? 'bg-coollabs-100 text-white' : ''">
                    <input type="radio" x-on:click="selected = 'monthly'" name="frequency" value="monthly"
                        class="sr-only">
                    <span>Monthly</span>
                </label>
                <label class="cursor-pointer rounded  px-2.5 py-1"
                    :class="selected === 'yearly' ? 'bg-coollabs-100 text-white' : ''">
                    <input type="radio" x-on:click="selected = 'yearly'" name="frequency" value="annually"
                        class="sr-only">
                    <span>Annually <span class="text-xs text-warning">(save ~1 month)<span></span>
                </label>
            </fieldset>
        </div>
        <div x-show="selected === 'monthly'" class="flex justify-center h-10 mt-3 text-sm leading-6 ">
            <div>Save <span class="font-bold text-warning">1 month</span> annually with the yearly plans.
            </div>
        </div>
        <div x-show="selected === 'yearly'" class="flex justify-center h-10 mt-3 text-sm leading-6 ">
            <div>
            </div>
        </div>
        <div class="flow-root mt-12">
            <div
                class="grid max-w-sm grid-cols-1 -mt-16 divide-y divide-coolgray-500 isolate gap-y-16 sm:mx-auto lg:-mx-8 lg:mt-0 lg:max-w-none lg:grid-cols-4 lg:divide-x lg:divide-y-0 xl:-mx-4">
                <div class="px-8 pt-16 lg:pt-0">
                    <h3 id="tier-trial" class="text-base font-semibold leading-7 text-white">Unlimited Trial</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight text-white">Free</span>
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight text-white">Still Free </span>
                        </span>
                    </p>
                    <span x-show="selected === 'monthly'" x-cloak>
                        <span>billed monthly</span>
                    </span>
                    <span x-show="selected === 'yearly'" x-cloak>
                        <span>billed annually</span>
                    </span>
                    <a href="https://github.com/coollabsio/coolify" aria-describedby="tier-trial" class="buyme">Get
                        Started</a>
                    <p class="mt-10 text-sm leading-6 text-white h-[6.5rem]">Start self-hosting without limits with our
                        OSS
                        version.</p>
                    <ul role="list" class="space-y-3 text-sm leading-6 ">
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            You manage everything
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
                        <li class="flex font-bold text-white gap-x-3">
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
                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-14">
                    <h3 id="tier-basic" class="text-base font-semibold leading-7 text-white">Basic</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight text-white">$5</span>
                            <span class="text-sm font-semibold leading-6 ">/month</span>
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight text-white">$4</span>
                            <span class="text-sm font-semibold leading-6 ">/month</span>
                        </span>
                    </p>
                    <span x-show="selected === 'monthly'" x-cloak>
                        <span>billed monthly</span>
                    </span>
                    <span x-show="selected === 'yearly'" x-cloak>
                        <span>billed annually</span>
                    </span>
                    @if ($showSubscribeButtons)
                        @isset($basic)
                            {{ $basic }}
                        @endisset
                    @endif
                    <p class="mt-10 text-sm leading-6 text-white h-[6.5rem]">Start self-hosting in
                        the cloud
                        with a
                        single
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
                            1 server <x-helper helper="Bring Your Own Server. All you need is n SSH connection." />
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
                        <li class="flex font-bold text-white gap-x-3">
                            <svg width="512" height="512" class="flex-none w-5 h-6 text-green-600"
                                viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2">
                                    <path
                                        d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3-5a9 9 0 0 0 6-8a3 3 0 0 0-3-3a9 9 0 0 0-8 6a6 6 0 0 0-5 3" />
                                    <path d="M7 14a6 6 0 0 0-3 6a6 6 0 0 0 6-3m4-8a1 1 0 1 0 2 0a1 1 0 1 0-2 0" />
                                </g>
                            </svg>
                            + All upcoming features
                        </li>
                    </ul>
                </div>
                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-14">
                    <h3 id="tier-pro" class="text-base font-semibold leading-7 text-white">Pro</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight text-white">$29</span>
                            <span class="text-sm font-semibold leading-6 ">/month</span>
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight text-white">$26</span>
                            <span class="text-sm font-semibold leading-6 ">/month</span>
                        </span>
                    </p>
                    <span x-show="selected === 'monthly'" x-cloak>
                        <span>billed monthly</span>
                    </span>
                    <span x-show="selected === 'yearly'" x-cloak>
                        <span>billed annually</span>
                    </span>
                    @if ($showSubscribeButtons)
                    @isset($pro)
                    {{ $pro }}
                @endisset
                    @endif
                    <p class="h-20 mt-10 text-sm leading-6 text-white">Scale your business or self-hosting environment.
                    </p>
                    <ul role="list" class="mt-6 space-y-3 text-sm leading-6 ">
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            5 servers <x-helper helper="Bring Your Own Server. All you need is n SSH connection." />
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
                        <li class="flex font-bold text-white gap-x-3">
                            <svg width="512" height="512" class="flex-none w-5 h-6 text-green-600"
                                viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2">
                                    <path
                                        d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3-5a9 9 0 0 0 6-8a3 3 0 0 0-3-3a9 9 0 0 0-8 6a6 6 0 0 0-5 3" />
                                    <path d="M7 14a6 6 0 0 0-3 6a6 6 0 0 0 6-3m4-8a1 1 0 1 0 2 0a1 1 0 1 0-2 0" />
                                </g>
                            </svg>
                            + All upcoming features
                        </li>
                    </ul>
                </div>
                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-14">
                    <h3 id="tier-ultimate" class="text-base font-semibold leading-7 text-white">Ultimate</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight text-white">$69</span>
                            <span class="text-sm font-semibold leading-6 ">/month</span>
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight text-white">$63</span>
                            <span class="text-sm font-semibold leading-6 ">/month</span>
                        </span>
                    </p>
                    <span x-show="selected === 'monthly'" x-cloak>
                        <span>billed monthly</span>
                    </span>
                    <span x-show="selected === 'yearly'" x-cloak>
                        <span>billed annually</span>
                    </span>
                    @if ($showSubscribeButtons)
                        @isset($ultimate)
                            {{ $ultimate }}
                        @endisset
                    @endif
                    <p class="h-20 mt-10 text-sm leading-6 text-white">Deploy complex infrastuctures and
                        manage them easily in one place.</p>
                    <ul role="list" class="mt-6 space-y-3 text-sm leading-6 ">
                        <li class="flex gap-x-3">
                            <svg class="flex-none w-5 h-6 text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            15 servers <x-helper helper="Bring Your Own Server. All you need is n SSH connection." />
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
                        <li class="flex font-bold text-white gap-x-3">
                            <svg width="512" height="512" class="flex-none w-5 h-6 text-green-600"
                                viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <g fill="none" stroke="currentColor" stroke-linecap="round"
                                    stroke-linejoin="round" stroke-width="2">
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
            <div class="pt-10">Need unlimited servers or official support for your Coolify instance? <a
                    href="https://docs.coollabs.io/contact" class='text-warning'>Contact us.</a>
            </div>
        </div>
    </div>
</div>
@isset($other)
    {{ $other }}
@endisset
