@props([
    'showSubscribeButtons' => true,
])
<div x-data="{ selected: 'monthly' }" class="w-full pb-20">
    <div class="px-6 mx-auto lg:px-8">
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
        @if (config('constants.limits.trial_period') > 0)
            <div class="py-2 text-center"><span
                    class="font-bold dark:text-warning">{{ config('constants.limits.trial_period') }}
                    days trial</span> included on all plans, without credit card details.</div>
        @endif
        <div x-show="selected === 'monthly'" class="flex justify-center h-10 mt-3 text-sm leading-6 ">
            <div>Save <span class="font-bold text-black dark:text-warning">10%</span> annually with the yearly plans.
            </div>
        </div>
        <div x-show="selected === 'yearly'" class="flex justify-center h-10 mt-3 text-sm leading-6 ">
            <div>
            </div>
        </div>
        <div class="p-4 rounded bg-coolgray-400">
            <h2 id="tier-hobby" class="flex items-start gap-4 text-4xl font-bold tracking-tight">Unlimited Trial
                <x-forms.button><a class="font-bold dark:text-white hover:no-underline"
                        href="https://github.com/coollabsio/coolify">Get Started</a></x-forms.button>
            </h2>
            <p class="mt-4 text-sm leading-6">Start self-hosting <span class="dark:text-warning">without limits</span>
                with
                our
                OSS version. Same features as the paid version, but you have to manage by yourself.</p>
        </div>

        <div class="flow-root mt-12">
            <div class="pb-10 text-xl text-center">For the detailed list of features, please visit our landing page: <a
                    class="font-bold underline dark:text-white" href="https://coolify.io">coolify.io</a></div>
            <div
                class="grid max-w-sm grid-cols-1 -mt-16 divide-y divide-neutral-200 dark:divide-coolgray-500 isolate gap-y-16 sm:mx-auto lg:-mx-8 lg:mt-0 lg:max-w-none lg:grid-cols-3 lg:divide-x lg:divide-y-0 xl:-mx-4">

                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-14">
                    <h3 id="tier-basic" class="text-base font-semibold leading-7 dark:text-white">Basic</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">$5</span>
                            <span class="text-sm font-semibold leading-6 ">/month + VAT</span>
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">$4</span>
                            <span class="text-sm font-semibold leading-6 ">/month + VAT</span>
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
                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-14">
                    <h3 id="tier-pro" class="text-base font-semibold leading-7 dark:text-white">Pro</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">$30</span>
                            <span class="text-sm font-semibold leading-6 ">/month + VAT</span>
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">$27</span>
                            <span class="text-sm font-semibold leading-6 ">/month + VAT</span>
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
                    <p class="h-20 mt-10 text-sm leading-6 dark:text-white">Expand your business or set up your own
                        hosting
                        environment.
                    </p>
                    <ul role="list" class="mt-6 space-y-3 text-sm leading-6 ">
                        <li class="flex ">
                            <svg class="flex-none w-5 h-6 mr-3 dark:text-warning" viewBox="0 0 20 20"
                                fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Connect <span class="px-1 font-bold dark:text-white">10</span> servers
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
                            Priority Email Support
                        </li>
                        <li class="flex font-bold dark:text-white gap-x-3">
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
                <div class="pt-16 lg:px-8 lg:pt-0 xl:px-12">
                    <h3 id="tier-ultimate" class="text-base font-semibold leading-7 dark:text-white">Ultimate</h3>
                    <p class="flex items-baseline mt-6 gap-x-1">
                        <span x-show="selected === 'monthly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">Custom</span>
                            {{-- <span class="text-sm font-semibold leading-6 ">pay-as-you-go</span> --}}
                        </span>
                        <span x-show="selected === 'yearly'" x-cloak>
                            <span class="text-4xl font-bold tracking-tight dark:text-white">Custom</span>
                            {{-- <span class="text-sm font-semibold leading-6 ">/month + VAT</span> --}}
                        </span>
                    </p>
                    <span x-show="selected === 'monthly'" x-cloak>
                        <span>pay-as-you-go</span>
                    </span>
                    <span x-show="selected === 'yearly'" x-cloak>
                        <span>pay-as-you-go</span>
                    </span>
                    @if ($showSubscribeButtons)
                        @isset($ultimate)
                            {{ $ultimate }}
                        @endisset
                    @endif
                    <p class="h-20 mt-10 text-sm leading-6 dark:text-white">Easily manage complex infrastructures in a
                        single location.</p>
                    <ul role="list" class="mt-6 space-y-3 text-sm leading-6 ">
                        <li class="flex ">
                            <svg class="flex-none w-5 h-6 mr-3 dark:text-warning" viewBox="0 0 20 20"
                                fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Connect <span class="px-1 font-bold dark:text-white">10+</span> servers
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
                        <li class="flex font-bold dark:text-white gap-x-3">
                            <svg class="flex-none w-5 h-6 dark:text-warning" viewBox="0 0 20 20" fill="currentColor"
                                aria-hidden="true">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z"
                                    clip-rule="evenodd" />
                            </svg>
                            Priority (Email/Chat) Support
                        </li>
                        <li class="flex font-bold dark:text-white gap-x-3">
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
            {{-- <div class="p-4 mt-10 rounded">
                <div class="flex items-start gap-4 text-xl tracking-tight">Need official support for
                    your self-hosted instance?
                    <x-forms.button>
                        <a class="font-bold dark:text-white hover:no-underline"
                            href="{{ config('coolify.contact') }}">Contact
                            Us</a>
                    </x-forms.button>
                </div>
            </div> --}}
        </div>

        {{-- <div class="pt-8 pb-12 text-4xl font-bold text-center dark:text-white">Included in all plans</div>
        <div class="grid grid-cols-1 gap-10 md:grid-cols-2 gap-y-28">
            <div>
                <div class="flex items-center gap-4 mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg dark:text-white bg-coolgray-500">
                        <svg width="512" height="512" class="icon" viewBox="0 0 24 24"
                            xmlns="http://www.w3.org/2000/svg">
                            <path fill="none" stroke="currentColor" stroke-linecap="round"
                                stroke-linejoin="round" stroke-width="2"
                                d="M3 7a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v2a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3zm12 13H6a3 3 0 0 1-3-3v-2a3 3 0 0 1 3-3h12M7 8v.01M7 16v.01M20 15l-2 3h3l-2 3" />
                        </svg>
                    </div>
                    <div class="text-2xl font-semibold dark:text-white">Bring Your Own Servers</div>
                </div>
                <div class="mt-1 text-base leading-7 text-gray-300">
                    Bring your own server from any cloud providers, or even your own server at home! All you need is SSH
                    access. You will have full control over your server, and you can even use it for other purposes.
                </div>
            </div>
            <div>
                <div class="flex items-center gap-4 mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg dark:text-white bg-coolgray-500">
                        <svg width="512" height="512" class="icon" viewBox="0 0 24 24"
                            xmlns="http://www.w3.org/2000/svg">
                            <g fill="none" stroke="white" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2">
                                <path
                                    d="M7 7h10a2 2 0 0 1 2 2v1l1 1v3l-1 1v3a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-3l-1-1v-3l1-1V9a2 2 0 0 1 2-2zm3 9h4" />
                                <circle cx="8.5" cy="11.5" r=".5" fill="#000000" />
                                <circle cx="15.5" cy="11.5" r=".5" fill="#000000" />
                                <path d="M9 7L8 3m7 4l1-4" />
                            </g>
                        </svg>
                    </div>
                    <div class="text-2xl font-semibold dark:text-white">Server Automations</div>
                </div>
                <div class="mt-1 text-base leading-7 text-gray-300">
                    Once you connected your server, Coolify will start managing it and do a
                    lot of administrative tasks for you. You can also write your own scripts to
                    automate your server<span class="dark:text-warning">*</span>.
                </div>
            </div>
            <div>
                <div class="flex items-center gap-4 mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg dark:text-white bg-coolgray-500">
                        <svg width="512" height="512" viewBox="0 0 24 24" class="icon"
                            xmlns="http://www.w3.org/2000/svg">
                            <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2">
                                <path
                                    d="M15 11h2a2 2 0 0 1 2 2v2m0 4a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2h4" />
                                <path
                                    d="M11 16a1 1 0 1 0 2 0a1 1 0 1 0-2 0m-3-5V8m.347-3.631A4 4 0 0 1 16 6M3 3l18 18" />
                            </g>
                        </svg>
                    </div>
                    <div class="text-2xl font-semibold dark:text-white">No Vendor Lock-in</div>
                </div>
                <div class="mt-1 text-base leading-7 text-gray-300">
                    You own your own data. All configurations saved on your own servers, so if
                    you decide to stop using Coolify, you can still continue to manage your
                    deployed resources.
                </div>
            </div>

            <div>
                <div class="flex items-center gap-4 mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg dark:text-white bg-coolgray-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <rect x="3" y="4" width="18" height="12" rx="1" />
                            <path d="M7 20h10" />
                            <path d="M9 16v4" />
                            <path d="M15 16v4" />
                            <path d="M7 10h2l2 3l2 -6l1 3h3" />
                        </svg>
                    </div>
                    <div class="text-2xl font-semibold dark:text-white">Monitoring</div>
                </div>
                <div class="mt-1 text-base leading-7 text-gray-300">
                    Coolify will automatically monitor your configured servers and deployed
                    resources. Notifies you if something goes wrong on your favourite
                    channels, like Discord, Telegram, via Email and more...
                </div>
            </div>
            <div>
                <div class="flex items-center gap-4 mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg dark:text-white bg-coolgray-500">
                        <svg width="512" height="512" class="icon" viewBox="0 0 24 24"
                            xmlns="http://www.w3.org/2000/svg">
                            <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2">
                                <path d="M6 4h10l4 4v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2" />
                                <path d="M10 14a2 2 0 1 0 4 0a2 2 0 1 0-4 0m4-10v4H8V4" />
                            </g>
                        </svg>
                    </div>
                    <div class="text-2xl font-semibold dark:text-white">Automatic Backups</div>
                </div>
                <div class="mt-1 text-base leading-7 text-gray-300">
                    We automatically backup your databases to any S3 compatible solution. If
                    something goes wrong, you can easily restore your data with a few clicks.
                </div>
            </div>
            <div>
                <div class="flex items-center gap-4 mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg dark:text-white bg-coolgray-500">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <polyline points="5 7 10 12 5 17" />
                            <line x1="13" y1="17" x2="19" y2="17" />
                        </svg>
                    </div>
                    <div class="text-2xl font-semibold dark:text-white">Powerful API</div>
                </div>
                <div class="mt-1 text-base leading-7 text-gray-300">
                    Programmatically deploy, query, and manage your servers & resources.
                    Integrate to your CI/CD pipelines, or build your own custom integrations. <span
                        class="dark:text-warning">*</span>
                </div>
            </div>
            <div>
                <div class="flex items-center gap-4 mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg dark:text-white bg-coolgray-500">
                        <svg width="512" height="512" class="icon" viewBox="0 0 24 24"
                            xmlns="http://www.w3.org/2000/svg">
                            <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2">
                                <path
                                    d="M4 18a2 2 0 1 0 4 0a2 2 0 1 0-4 0M4 6a2 2 0 1 0 4 0a2 2 0 1 0-4 0m12 12a2 2 0 1 0 4 0a2 2 0 1 0-4 0M6 8v8" />
                                <path d="M11 6h5a2 2 0 0 1 2 2v8" />
                                <path d="m14 9l-3-3l3-3" />
                            </g>
                        </svg>
                    </div>
                    <div class="text-2xl font-semibold dark:text-white">Push to Deploy</div>
                </div>
                <div class="mt-1 text-base leading-7 text-gray-300">
                    Git integration is default today. We support hosted (github.com,
                    gitlab.com<span class="inline-block dark:text-warning">*</span>) or self-hosted<span
                        class="dark:text-warning">*</span>
                    (Github Enterprise, Gitlab) Git repositories.
                </div>
            </div>
            <div>
                <div class="flex items-center gap-4 mb-4">
                    <div class="flex items-center justify-center w-10 h-10 rounded-lg dark:text-white bg-coolgray-500">
                        <svg width="512" height="512" class="icon" viewBox="0 0 24 24"
                            xmlns="http://www.w3.org/2000/svg">
                            <path fill="none" stroke="currentColor" stroke-linecap="round"
                                stroke-linejoin="round" stroke-width="2"
                                d="M10 13a2 2 0 1 0 4 0a2 2 0 0 0-4 0m-2 8v-1a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v1M15 5a2 2 0 1 0 4 0a2 2 0 0 0-4 0m2 5h2a2 2 0 0 1 2 2v1M5 5a2 2 0 1 0 4 0a2 2 0 0 0-4 0m-2 8v-1a2 2 0 0 1 2-2h2" />
                        </svg>
                    </div>
                    <div class="text-2xl font-semibold dark:text-white">Pull Request Deployments</div>
                </div>
                <div class="mt-1 text-base leading-7 text-gray-300">
                    Automagically deploy new commits and pull requests separately to quickly
                    review contributions and speed up your teamwork!
                </div>
            </div>
        </div>
        <div class="pt-20 text-xs">
            <span class="dark:text-warning">*</span> Some features are work in progress and will be available soon.
        </div> --}}
    </div>
    @isset($other)
        {{ $other }}
    @endisset
