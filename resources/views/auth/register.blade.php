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
                    <h1 class="text-xl font-bold leading-tight tracking-tight text-gray-900 md:text-2xl dark:text-white">
                        Create an account
                    </h1>
                    <form action="/register" method="POST" class="flex flex-col gap-2">
                        @csrf
                        <x-forms.input id="name" required type="text" name="name" value="{{ $name }}"
                            label="{{ __('input.name') }}" />
                        <x-forms.input id="email" required type="email" name="email" value="{{ $email }}"
                            label="{{ __('input.email') }}" />
                        <div class="flex gap-2">
                            <x-forms.input id="password" required type="password" name="password"
                                label="{{ __('input.password') }}" />
                            <x-forms.input id="password_confirmation" required type="password"
                                name="password_confirmation" label="{{ __('input.password.again') }}" />
                        </div>
                        <x-forms.button class="mb-4" type="submit">Register</x-forms.button>
                        <a href="/login" class="button bg-coollabs-gradient">
                            {{ __('auth.already_registered') }}
                        </a>
                    </form>
                    @if ($errors->any())
                        <div class="text-xs text-center text-error">
                            @foreach ($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                </div>
            </div>
        </div>
    </section>
</x-layout-simple>
