<?php
function getOldOrLocal($key, $localValue)
{
    return old($key) != '' ? old($key) : (app()->environment('local') ? $localValue : '');
}

$name = getOldOrLocal('name', 'test3 normal user');
$email = getOldOrLocal('email', 'test3@example.com');
?>

<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <a class="flex items-center mb-6 text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                Coolify
            </a>
            <div class="w-full bg-white rounded-lg shadow md:mt-0 sm:max-w-md xl:p-0 dark:bg-base">
                <div class="p-6 space-y-4 md:space-y-6 sm:p-8">
                    <div>
                        <h1
                            class="text-xl font-bold leading-tight tracking-tight text-gray-900 md:text-2xl dark:text-white">
                            Create an account
                        </h1>
                        @if ($isFirstUser)
                            <div class="text-xs dark:text-warning">This user will be the root user (full admin access).
                            </div>
                        @endif
                    </div>
                    <form action="/register" method="POST" class="flex flex-col gap-2">
                        @csrf
                        <x-forms.input id="name" required type="text" name="name" value="{{ $name }}"
                            label="{{ __('input.name') }}" />
                        <x-forms.input id="email" required type="email" name="email" value="{{ $email }}"
                            label="{{ __('input.email') }}" />
                        <x-forms.input id="password" required type="password" name="password"
                            label="{{ __('input.password') }}" />
                        <x-forms.input id="password_confirmation" required type="password" name="password_confirmation"
                            label="{{ __('input.password.again') }}" />
                        <div class="text-xs w-full">Your password should be min 8 characters long and contain
                            at least one uppercase letter, one lowercase letter, one number, and one symbol.</div>
                        <div class="flex flex-col gap-4 pt-8 w-full">
                            <x-forms.button class="w-full" type="submit">Register</x-forms.button>
                            <a href="/login" class="w-full text-xs">
                                {{ __('auth.already_registered') }}
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-layout-simple>
