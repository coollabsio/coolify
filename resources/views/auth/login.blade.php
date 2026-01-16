<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <div class="w-full max-w-md space-y-8">
                <div class="text-center space-y-2">
                    <h1 class="!text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                        Coolify
                    </h1>
                </div>

                <div class="space-y-6">
                    @if (session('status'))
                        <div class="mb-6 p-4 bg-success/10 border border-success rounded-lg">
                            <p class="text-sm text-success">{{ session('status') }}</p>
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="mb-6 p-4 bg-error/10 border border-error rounded-lg">
                            <p class="text-sm text-error">{{ session('error') }}</p>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-6 p-4 bg-error/10 border border-error rounded-lg">
                            @foreach ($errors->all() as $error)
                                <p class="text-sm text-error">{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    <form action="/login" method="POST" class="flex flex-col gap-4">
                        @csrf
                        @env('local')
                            <x-forms.input value="test@example.com" type="email" autocomplete="email" name="email" required
                                label="{{ __('input.email') }}" />
                            <x-forms.input value="password" type="password" autocomplete="current-password" name="password"
                                required label="{{ __('input.password') }}" />
                        @else
                            <x-forms.input type="email" name="email" autocomplete="email" required
                                label="{{ __('input.email') }}" />
                            <x-forms.input type="password" name="password" autocomplete="current-password" required
                                label="{{ __('input.password') }}" />
                        @endenv

                        <div class="flex items-center justify-between">
                            <a href="/forgot-password"
                                class="text-sm dark:text-neutral-400 hover:text-coollabs dark:hover:text-warning hover:underline transition-colors">
                                {{ __('auth.forgot_password_link') }}
                            </a>
                        </div>

                        <x-forms.button class="w-full justify-center py-3 box-boarding" type="submit" isHighlighted>
                            {{ __('auth.login') }}
                        </x-forms.button>
                    </form>

                    @if ($is_registration_enabled)
                        <div class="relative my-6">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                            </div>
                            <div class="relative flex justify-center text-sm">
                                <span class="px-2 bg-gray-50 dark:bg-base text-neutral-500 dark:text-neutral-400 ">
                                    Don't have an account?
                                </span>
                            </div>
                        </div>
                        <a href="/register"
                            class="block w-full text-center py-3 px-4 rounded-lg border border-neutral-300 dark:border-coolgray-400 font-medium hover:border-coollabs dark:hover:border-warning transition-colors">
                            {{ __('auth.register_now') }}
                        </a>
                    @else
                        <div class="mt-6 text-center text-sm text-neutral-500 dark:text-neutral-400">
                            {{ __('auth.registration_disabled') }}
                        </div>
                    @endif

                    @if ($enabled_oauth_providers->isNotEmpty())
                        <div class="relative my-6">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                            </div>
                            <div class="relative flex justify-center text-sm">
                                <span class="px-2 bg-gray-50 dark:bg-base text-neutral-500 dark:text-neutral-400">or
                                    continue with</span>
                            </div>
                        </div>
                        <div class="flex flex-col gap-3">
                            @foreach ($enabled_oauth_providers as $provider_setting)
                                <x-forms.button class="w-full justify-center" type="button"
                                    onclick="document.location.href='/auth/{{ $provider_setting->provider }}/redirect'">
                                    {{ __("auth.login.$provider_setting->provider") }}
                                </x-forms.button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </section>
</x-layout-simple>