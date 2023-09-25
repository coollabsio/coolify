@extends('layouts.base')
@section('body')
    @parent
    <x-navbar />
    <div class="fixed z-50 top-[4.5rem] left-4" id="vue">
        <magic-bar></magic-bar>
    </div>
    <main class="main max-w-screen-2xl">
        {{ $slot }}
    </main>
@endsection
