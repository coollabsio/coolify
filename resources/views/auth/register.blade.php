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
            <div class="w-full max-w-md space-y-8">
                <div class="text-center space-y-2">
                    <h1 class="!text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                        Coolify
                    </h1>
                    <p class="text-lg dark:text-neutral-400">
                        Create your account
                    </p>
                </div>

                <div class="space-y-6">
                    @if ($isFirstUser)
                        <div class="mb-6 p-4 bg-warning/10 border border-warning rounded-lg">
                            <div class="flex gap-3">
                                <svg class="size-5 text-warning flex-shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                        clip-rule="evenodd" />
                                </svg>
                                <div>
                                    <p class="font-bold text-warning">Root User Setup</p>
                                    <p class="text-sm dark:text-white text-black">This user will be the root user with full
                                        admin access.</p>
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="mb-6 p-4 bg-error/10 border border-error rounded-lg">
                            @foreach ($errors->all() as $error)
                                <p class="text-sm text-error">{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    <form action="/register" method="POST" class="flex flex-col gap-4">
                        @csrf
                        <x-forms.input id="name" required type="text" name="name" value="{{ $name }}"
                            label="{{ __('input.name') }}" />
                        <x-forms.input id="email" required type="email" name="email" value="{{ $email }}"
                            label="{{ __('input.email') }}" />
                        <x-forms.input id="password" required type="password" name="password"
                            label="{{ __('input.password') }}" />
                        <x-forms.input id="password_confirmation" required type="password" name="password_confirmation"
                            label="{{ __('input.password.again') }}" />

                        <div
                            class="p-4 bg-neutral-50 dark:bg-coolgray-200 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                            <p class="text-xs dark:text-neutral-400">
                                Your password should be min 8 characters long and contain at least one uppercase letter,
                                one lowercase letter, one number, and one symbol.
                            </p>
                        </div>

                        <x-forms.button class="w-full justify-center py-3 box-boarding mt-2" type="submit"
                            isHighlighted>
                            Create Account
                        </x-forms.button>
                    </form>

                    <div class="relative my-6">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 bg-gray-50 dark:bg-base text-neutral-500 dark:text-neutral-400">
                                Already have an account?
                            </span>
                        </div>
                    </div>

                    <a href="/login"
                        class="block w-full text-center py-3 px-4 rounded-lg border border-neutral-300 dark:border-coolgray-400 font-medium hover:border-coollabs dark:hover:border-warning transition-colors">
                        {{ __('auth.already_registered') }}
                    </a>
                </div>
            </div>
        </div>
    </section>
</x-layout-simple>