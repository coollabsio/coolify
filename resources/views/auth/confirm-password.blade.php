<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <div class="w-full max-w-md space-y-8">
                <div class="text-center space-y-2">
                    <h1 class="!text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                        Coolify
                    </h1>
                    <p class="text-lg dark:text-neutral-400">
                        Confirm Your Password
                    </p>
                </div>

                <div class="space-y-6">
                    @if (session('status'))
                        <div class="p-4 bg-success/10 border border-success rounded-lg">
                            <p class="text-sm text-success">{{ session('status') }}</p>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="p-4 bg-error/10 border border-error rounded-lg">
                            @foreach ($errors->all() as $error)
                                <p class="text-sm text-error">{{ $error }}</p>
                            @endforeach
                        </div>
                    @endif

                    <div class="p-4 bg-neutral-50 dark:bg-coolgray-200 rounded-lg border border-neutral-200 dark:border-coolgray-400">
                        <div class="flex gap-3">
                            <svg class="size-5 flex-shrink-0 mt-0.5 text-coollabs dark:text-warning" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p class="text-sm dark:text-neutral-400">
                                This is a secure area. Please confirm your password before continuing.
                            </p>
                        </div>
                    </div>

                    <form action="/user/confirm-password" method="POST" class="flex flex-col gap-4">
                        @csrf
                        <x-forms.input required type="password" name="password" label="{{ __('input.password') }}" />
                        <x-forms.button class="w-full justify-center py-3 box-boarding" type="submit" isHighlighted>
                            {{ __('auth.confirm_password') }}
                        </x-forms.button>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-layout-simple>
