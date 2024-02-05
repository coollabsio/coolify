@extends('layouts.base')
@section('body')
    @parent
    @if (isSubscriptionOnGracePeriod())
        @persist('magic-bar')
            <div class="fixed top-[4.5rem] left-4 z-50" id="vue">
                <magic-bar></magic-bar>
            </div>
        @endpersist
        <x-navbar />
    @else
        <x-navbar-subscription />
    @endif

    <main class="mx-auto main max-w-screen-2xl">
        {{ $slot }}
    </main>
@endsection
