@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="419"
            title="This page is definitely old, not like you!"
            description="Your session has expired. Please log in again to continue."
            :show-go-back="false"
            :show-dashboard="false"
            primary-href="/login"
            primary-label="Back to login">
            <x-forms.collapsible title="Using a reverse proxy or Cloudflare Tunnel?" class="error-proxy-help">
                <ul>
                    <li>Set your domain in <strong>Settings &rarr; FQDN</strong> to match the URL you use to access Coolify.</li>
                    <li>Cloudflare users: disable <strong>Browser Integrity Check</strong> and <strong>Under Attack Mode</strong> for your Coolify domain, as these can interrupt login sessions.</li>
                    <li>If you can still access Coolify via <code>localhost</code>, log in there first to configure your FQDN.</li>
                </ul>
            </x-forms.collapsible>
        </x-error-page>
        @livewireScripts
    </body>
@endsection
