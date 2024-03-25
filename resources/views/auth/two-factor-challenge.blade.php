<x-layout-simple>
    <div class="flex items-center justify-center h-screen">
        <div>
            <div class="flex flex-col items-center pb-8">
                <div class="text-5xl font-bold tracking-tight text-center dark:text-white">Coolify</div>
                {{-- <x-version /> --}}
            </div>
            <div class="w-96">
                <form action="/two-factor-challenge" method="POST" class="flex flex-col gap-2">
                    @csrf
                    <div>
                        <x-forms.input autofocus type="number" name="code" label="{{ __('input.code') }}" autofocus />
                        {{-- <div class="pt-2 text-xs cursor-pointer hover:underline hover:dark:text-white"
                            x-on:click="showRecovery = !showRecovery">Use
                            Recovery Code
                        </div> --}}
                    </div>
                    <div>
                        <x-forms.input name="recovery_code" label="{{ __('input.recovery_code') }}" />
                        {{-- <div class="pt-2 text-xs cursor-pointer hover:underline hover:dark:text-white"
                            x-on:click="showRecovery = !showRecovery">Use
                            One-Time Code
                        </div> --}}
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
</x-layout-simple>
