@extends('layouts.base')
@section('body')
    @parent
    @if (isSubscribed() || !isCloud())
        <livewire:layout-popups />
    @endif
    @auth
        <div class="rw-root" x-data>
            {{ $slot }}
        </div>
    @endauth
@endsection
