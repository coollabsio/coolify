<x-layout-simple>
    <x-auth.shell title="Coolify" description="Sign in to manage your applications and infrastructure.">
        <div class="flex flex-col gap-4">
            @if (session('status'))
                <x-auth.alert type="success">{{ session('status') }}</x-auth.alert>
            @endif

            @if (session('error'))
                <x-auth.alert type="error">{{ session('error') }}</x-auth.alert>
            @endif

            @if ($errors->any())
                <x-auth.alert type="error">
                    <div class="flex flex-col gap-1">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                </x-auth.alert>
            @endif

            <form action="/login" method="POST" class="flex flex-col gap-4">
                @csrf
                @env('local')
                    <x-forms.input value="test@example.com" type="email" autocomplete="email" name="email" required
                        autofocus label="{{ __('input.email') }}" />
                    <x-forms.input value="password" type="password" autocomplete="current-password" name="password"
                        required label="{{ __('input.password') }}" />
                @else
                    <x-forms.input type="email" name="email" autocomplete="email" required autofocus
                        label="{{ __('input.email') }}" />
                    <x-forms.input type="password" name="password" autocomplete="current-password" required
                        label="{{ __('input.password') }}" />
                @endenv

                <div class="flex justify-end">
                    @if (is_transactional_emails_enabled())
                        <a href="/forgot-password" class="auth-text-link">
                            {{ __('auth.forgot_password_link') }}
                        </a>
                    @else
                        <span class="relative inline-flex"
                            x-data="{ visible: false, _t: null }"
                            @mouseenter="_t = setTimeout(() => {
                                visible = true;
                                $nextTick(() => requestAnimationFrame(() => {
                                    const tip = $refs.tip;
                                    if (!tip) return;
                                    const r = $el.getBoundingClientRect();
                                    const t = tip.getBoundingClientRect();
                                    let top = r.top - t.height - 6;
                                    let left = r.left;
                                    if (top < 4) top = r.bottom + 6;
                                    if (left + t.width > innerWidth - 8) left = innerWidth - 8 - t.width;
                                    if (left < 4) left = 4;
                                    tip.style.top = top + 'px';
                                    tip.style.left = left + 'px';
                                }));
                            }, 300)"
                            @mouseleave="clearTimeout(_t); visible = false"
                            @focusin="visible = true"
                            @focusout="visible = false">
                            <span tabindex="0" role="link" aria-disabled="true"
                                class="auth-text-link cursor-not-allowed opacity-50"
                                aria-describedby="forgot-password-disabled-tooltip">
                                {{ __('auth.forgot_password_link') }}
                            </span>
                            <div id="forgot-password-disabled-tooltip" x-ref="tip" x-show="visible" x-cloak
                                class="auth-tooltip max-w-xs whitespace-normal">
                                {{ __('auth.forgot_password_disabled_tooltip') }}
                            </div>
                        </span>
                    @endif
                </div>

                <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
                    {{ __('auth.login') }}
                </x-forms.button>
            </form>

            @if ($enabled_oauth_providers->isNotEmpty())
                <div class="auth-divider"><span>Or continue with</span></div>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($enabled_oauth_providers as $provider_setting)
                        <x-forms.button class="w-full justify-center" type="button"
                            onclick="document.location.href='/auth/{{ $provider_setting->provider }}/redirect'">
                            {{ __("auth.login.$provider_setting->provider") }}
                        </x-forms.button>
                    @endforeach
                </div>
            @endif
        </div>

        <x-slot:footer>
            @if ($is_registration_enabled)
                <span>New to Coolify?</span>
                <a href="/register" class="auth-text-link">{{ __('auth.register_now') }}</a>
            @else
                <span>{{ __('auth.registration_disabled') }}</span>
            @endif
        </x-slot:footer>
    </x-auth.shell>
</x-layout-simple>
