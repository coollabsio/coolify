@extends('proxy.layout')

@section('title', 'Under maintenance')

@section('content')
    <p class="status" aria-label="503 Service Unavailable">503</p>
    <h1>We are doing some maintenance</h1>
    <p><span id="host">This site</span> is temporarily unavailable for maintenance. Please check back soon.</p>
@endsection
