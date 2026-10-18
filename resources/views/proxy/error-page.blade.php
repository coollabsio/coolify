@extends('proxy.layout')

@section('title', 'Site not available')

@section('content')
    <p class="status">503 Service Unavailable</p>
    <h1>This site is not available at the moment</h1>
    <p><span id="host">This site</span> cannot answer your request right now. Please try again later.</p>
    <section>
        <h2>Are you the site owner?</h2>
        <ul>
            <li>The domain is not configured on a resource in Coolify, or its DNS points to another server.</li>
            <li>The application is stopped, still deploying, or has crashed.</li>
            <li>The application health check is failing.</li>
        </ul>
        <a href="https://coolify.io/docs/troubleshoot/applications/no-available-server" rel="noopener noreferrer">Read the troubleshooting guide</a>
    </section>
@endsection
