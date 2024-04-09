<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <a class="flex items-center mb-6 text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                Coolify
            </a>
            <div class="w-full bg-white shadow md:mt-0 sm:max-w-md xl:p-0 dark:bg-base ">
                <div class="p-6 space-y-4 md:space-y-6 sm:p-8">
                    <form action="/login" method="POST" class="flex flex-col gap-2">
                        @csrf
                        @env('local')
                        <x-forms.input value="test@example.com" type="email" autocomplete="email" name="email"
                            required label="{{ __('input.email') }}" autofocus />

                        <x-forms.input value="password" type="password" autocomplete="current-password" name="password"
                            required label="{{ __('input.password') }}" />

                        <a href="/forgot-password" class="text-xs">
                            {{ __('auth.forgot_password') }}?
                        </a>
                    @else
                        <x-forms.input type="email" name="email" autocomplete="email" required
                            label="{{ __('input.email') }}" autofocus />
                        <x-forms.input type="password" name="password" autocomplete="current-password" required
                            label="{{ __('input.password') }}" />
                        <a href="/forgot-password" class="text-xs">
                            {{ __('auth.forgot_password') }}?
                        </a>
                        @endenv
                        <x-forms.button class="mt-10" type="submit">{{ __('auth.login') }}</x-forms.button>

                        @if (!$is_registration_enabled)
                            <div class="text-center text-neutral-500">{{ __('auth.registration_disabled') }}</div>
                        @endif
                        @if ($errors->any())
                            <div class="text-xs text-center text-error">
                                @foreach ($errors->all() as $error)
                                    <p>{{ $error }}</p>
                                @endforeach
                            </div>
                        @endif
                        @if (session('status'))
                            <div class="mb-4 font-medium text-green-600">
                                {{ session('status') }}
                            </div>
                        @endif
                        @if (session('error'))
                            <div class="mb-4 font-medium text-red-600">
                                {{ session('error') }}
                            </div>
                        @endif
                    </form>
                    @if ($is_registration_enabled)
                        <a href="/register" class="button bg-coollabs-gradient">
                            {{ __('auth.register_now') }}
                        </a>
                    @endif
                    @if ($enabled_oauth_providers->isNotEmpty())
                        <div class="relative">
                            <div class="absolute inset-0 flex items-center" aria-hidden="true">
                                <div class="w-full border-t dark:border-coolgray-200"></div>
                            </div>
                            <div class="relative flex justify-center">
                                <span class="px-2 text-sm dark:text-neutral-500 dark:bg-base">or</span>
                            </div>
                        </div>
                    @endif
                    @foreach ($enabled_oauth_providers as $provider_setting)
                        <x-forms.button class="w-full" type="button"
                            onclick="document.location.href='/auth/{{ $provider_setting->provider }}/redirect'">
                            {{ __("auth.login.$provider_setting->provider") }}
                        </x-forms.button>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
</x-layout-simple>
