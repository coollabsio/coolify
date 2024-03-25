<x-layout-simple>
    <div class="flex items-center justify-center h-screen">
        <div>
            <div class="flex flex-col items-center pb-8">
                <div class="text-5xl font-bold tracking-tight text-center dark:text-white">Coolify</div>
                {{-- <x-version /> --}}
            </div>
            <div class="w-96">
                <form action="/user/confirm-password" method="POST" class="flex flex-col gap-2">
                    @csrf
                    <x-forms.input required type="password" name="password" label="{{ __('input.password') }}" autofocus />
                    <x-forms.button type="submit">{{ __('auth.confirm_password') }}</x-forms.button>
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
</x-layout-simple>
