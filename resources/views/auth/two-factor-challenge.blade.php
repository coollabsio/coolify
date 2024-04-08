<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base" x-data="{ showRecovery: false }">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <a class="flex items-center mb-6 text-5xl font-extrabold tracking-tight text-gray-900 dark:text-white">
                Coolify
            </a>
            <div class="w-full bg-white shadow md:mt-0 sm:max-w-md xl:p-0 dark:bg-base ">
                <div class="p-6 space-y-4 md:space-y-6 sm:p-8">
                    <form action="/two-factor-challenge" method="POST" class="flex flex-col gap-2">
                        @csrf
                        <div>
                            <x-forms.input type="number" name="code" autocomplete="one-time-code" label="{{ __('input.code') }}" autofocus />
                            <div x-show="!showRecovery"
                                class="pt-2 text-xs cursor-pointer hover:underline hover:dark:text-white"
                                x-on:click="showRecovery = !showRecovery">Enter
                                Recovery Code
                            </div>
                        </div>
                        <div x-show="showRecovery" x-cloak>
                            <x-forms.input name="recovery_code" label="{{ __('input.recovery_code') }}" />
                        </div>
                        <x-forms.button type="submit">{{ __('auth.login') }}</x-forms.button>
                    </form>
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
                </div>
            </div>
        </div>
    </section>
</x-layout-simple>
