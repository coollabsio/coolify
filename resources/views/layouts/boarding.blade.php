@extends('layouts.base')
@section('body')
    <div>

    </div>
    {{ $slot }}
    {{-- <main class="min-h-screen hero">
        <div class="hero-content">
            {{ $slot }}
        </div>
    </main> --}}
    @parent
@endsection
