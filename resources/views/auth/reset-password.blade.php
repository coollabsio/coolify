<x-layout-simple>
    <section class="bg-gray-50 dark:bg-base">
        <div class="flex flex-col items-center justify-center px-6 py-8 mx-auto md:h-screen lg:py-0">
            <a class="flex items-center text-5xl font-extrabold tracking-tight">
                <img src="/publify-logo-horizontal-dark.png" alt="Publify Logo" class="visible w-[300px] h-auto dark:hidden dark:w-0 dark:h-0">
                <img src="/publify-logo-horizontal-light.png" alt="Publify Logo" class="hidden dark:visible dark:block dark:w-[300px] dark:h-auto">
            </a>
            <div class="flex items-center justify-center pb-6 text-center">
                {{ __('auth.reset_password') }}
            </div>
            <div class="w-full bg-white shadow md:mt-0 sm:max-w-md xl:p-0 dark:bg-base ">
                <div class="p-6 space-y-4 md:space-y-6 sm:p-8">
                    <form action="/reset-password" method="POST" class="flex flex-col gap-2">
                        @csrf
                        <input hidden id="token" name="token" value="{{ request()->route('token') }}">
                        <input hidden value="{{ request()->query('email') }}" type="email" name="email"
                            label="{{ __('input.email') }}" />
                        <div class="flex flex-col gap-2">
                            <x-forms.input required type="password" id="password" name="password"
                                label="{{ __('input.password') }}" autofocus />
                            <x-forms.input required type="password" id="password_confirmation"
                                name="password_confirmation" label="{{ __('input.password.again') }}" />
                        </div>
                        <x-forms.button type="submit">{{ __('auth.reset_password') }}</x-forms.button>
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
