<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <div class="w-full max-w-md space-y-8">
                <div class="text-center space-y-2">
                    <h1 class="!text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                        Coolify
                    </h1>
                    <p class="text-lg dark:text-neutral-400">
                        {{ __('auth.forgot_password_heading') }}
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

                    @if (is_transactional_emails_enabled())
                        <form action="/forgot-password" method="POST" class="flex flex-col gap-4">
                            @csrf
                            <x-forms.input required type="email" name="email" label="{{ __('input.email') }}" />
                            <x-forms.button class="w-full justify-center py-3 box-boarding" type="submit" isHighlighted>
                                {{ __('auth.forgot_password_send_email') }}
                            </x-forms.button>
                        </form>
                    @else
                        <div class="p-4 bg-warning/10 border border-warning rounded-lg mb-6">
                            <div class="flex gap-3">
                                <svg class="size-5 text-warning flex-shrink-0 mt-0.5" xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd"
                                        d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"
                                        clip-rule="evenodd" />
                                </svg>
                                <div>
                                    <p class="font-bold text-warning mb-2">Email Not Configured</p>
                                    <p class="text-sm dark:text-white text-black mb-2">
                                        Transactional emails are not active on this instance.
                                    </p>
                                    <p class="text-sm dark:text-white text-black">
                                        See how to set it in our <a class="font-bold underline hover:text-coollabs"
                                            target="_blank" href="{{ config('constants.urls.docs') }}">documentation</a>, or
                                        learn how to manually reset your password.
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="relative my-6">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-neutral-300 dark:border-coolgray-400"></div>
                        </div>
                        <div class="relative flex justify-center text-sm">
                            <span class="px-2 dark:bg-base text-neutral-500 dark:text-neutral-400">
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