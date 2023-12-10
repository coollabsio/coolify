@extends('layouts.base')
@section('body')
    @parent
    <x-navbar />
    @persist('magic-bar')
        <div class="fixed z-30 top-[4.5rem] left-4" id="vue">
            <magic-bar></magic-bar>
        </div>
    @endpersist
    <livewire:sponsorship />
    <main class="pb-10 main max-w-screen-2xl">
        {{ $slot }}
    </main>
    <script data-navigate-once>
        @auth
        if ("{{ auth()->user()->id }}" == 0) {
            let checkPusherInterval = null;
            let checkNumber = 0;
            let errorMessage =
                "Coolify could not connect to the new realtime service introduced in beta.154.<br>Please check the related <a href='https://coolify.io/docs/cloudflare-tunnels' target='_blank'>documentation</a> or get help on <a href='https://coollabs.io/discord' target='_blank'>Discord</a>.";
            checkPusherInterval = setInterval(() => {
                if (window.Echo) {
                    if (window.Echo.connector.pusher.connection.state !== 'connected') {
                        checkNumber++;
                        if (checkNumber > 5) {
                            clearInterval(checkPusherInterval);
                            Livewire.emit('error', errorMessage);
                        }
                    } else {
                        console.log('Coolify is now connected to the new realtime service introduced in beta.154.');
                        clearInterval(checkPusherInterval);
                    }
                } else {
                    clearInterval(checkPusherInterval);
                    Livewire.emit('error', errorMessage);
                }
            }, 2000);
        }
        @endauth
    </script>
@endsection
