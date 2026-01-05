<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <div class="w-full max-w-md space-y-8">
                <div class="text-center space-y-2">
                    <h1 class="!text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                        Coolify
                    </h1>
                    <p class="text-lg dark:text-neutral-400">
                        {{ __('auth.reset_password') }}
                    </p>
                </div>

                <div class="space-y-6">
                    @if (session('status'))
                        <div class="mb-6 p-4 bg-success/10 border border-success rounded-lg">
                            <div class="flex gap-3">
                                <svg class="size-5 text-success flex-shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                        clip-rule="evenodd" />
                                </svg>
                                <p class="text-sm text-success">{{ session('status') }}</p>
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

                    <div class="mb-6">
                        <p class="text-sm dark:text-neutral-400">
                            Enter your new password below. Make sure it's strong and secure.
                        </p>
                    </div>

                    <form action="/reset-password" method="POST" class="flex flex-col gap-4">
                        @csrf
                        <input hidden id="token" name="token" value="{{ request()->route('token') }}">
                        <input hidden value="{{ request()->query('email') }}" type="email" name="email"
                            label="{{ __('input.email') }}" />
                        <x-forms.input required type="password" id="password" name="password"
                            label="{{ __('input.password') }}" />
                        <x-forms.input required type="password" id="password_confirmation" name="password_confirmation"
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
                            {{ __('auth.reset_password') }}
                        </x-forms.button>
                    </form>

                    <div class="relative my-6">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 bg-gray-50 dark:bg-base text-neutral-500 dark:text-neutral-400">
                                Remember your password?
                            </span>
                        </div>
                    </div>

                    <a href="/login"
                        class="block w-full text-center py-3 px-4 rounded-lg border border-neutral-300 dark:border-coolgray-400 font-medium hover:border-coollabs dark:hover:border-warning transition-colors">
                        Back to Login
                    </a>
                </div>
            </div>
        </div>
    </section>
</x-layout-simple>