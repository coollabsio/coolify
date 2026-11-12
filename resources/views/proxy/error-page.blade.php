@extends('proxy.layout')

@if (($status ?? 404) === 503)
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
@else
    @section('title', 'Site not found')

    @section('content')
        <p class="status">404 Not Found</p>
        <h1>There is no site at this address</h1>
        <p><span id="host">This site</span> is not configured on this server.</p>
        <section>
            <h2>Are you the site owner?</h2>
            <ul>
                <li>The domain is not configured on a resource in Coolify.</li>
                <li>The DNS record of the domain points to another server.</li>
            </ul>
            <a href="https://coolify.io/docs/troubleshoot/applications/no-available-server" rel="noopener noreferrer">Read the troubleshooting guide</a>
        </section>
    @endsection
@endif
