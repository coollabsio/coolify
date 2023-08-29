@extends('layouts.base')
@section('body')
<main class="min-h-screen hero">
    <div class="hero-content">
        {{ $slot }}
    </div>
</main>
@parent
@endsection
