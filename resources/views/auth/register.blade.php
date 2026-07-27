<?php
if (! function_exists('getOldOrLocal')) {
    function getOldOrLocal($key, $localValue)
    {
        return old($key) != '' ? old($key) : (app()->environment('local') ? $localValue : '');
    }
}

$name = getOldOrLocal('name', 'test3 normal user');
$email = getOldOrLocal('email', 'test3@example.com');
?>

<x-layout-simple>
    <x-auth.shell :title="$isFirstUser ? 'Create the root account' : 'Create your account'">
        <div class="flex flex-col gap-4">
            @if ($isFirstUser)
                <x-auth.alert type="warning">
                    <p class="font-medium">Full instance access</p>
                    <p class="mt-0.5 text-black/70 dark:text-white/70">This first account becomes the root user.</p>
                </x-auth.alert>
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

            <form action="/register" method="POST" class="flex flex-col gap-4">
                @csrf
                <x-forms.input id="name" required type="text" name="name" value="{{ $name }}" autofocus
                    autocomplete="name" label="{{ __('input.name') }}" />
                <x-forms.input id="email" required type="email" name="email" value="{{ $email }}"
                    autocomplete="email" label="{{ __('input.email') }}" />
                <x-forms.input id="password" required type="password" name="password" autocomplete="new-password"
                    label="{{ __('input.password') }}" />
                <x-forms.input id="password_confirmation" required type="password" name="password_confirmation"
                    autocomplete="new-password" label="{{ __('input.password.again') }}" />

                <div class="auth-guidance">
                    <x-reicon name="info-circle" class="mt-0.5 size-4 shrink-0" />
                    <p>Use at least 8 characters with uppercase, lowercase, number, and symbol.</p>
                </div>

                <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
                    Create account
                </x-forms.button>
            </form>
        </div>

        @if (! $isFirstUser)
            <x-slot:footer>
                <span>Already have an account?</span>
                <a href="{{ route('login') }}"
                    class="auth-text-link underline">{{ __('auth.already_registered') }}</a>
            </x-slot:footer>
        @endif
    </x-auth.shell>
</x-layout-simple>
