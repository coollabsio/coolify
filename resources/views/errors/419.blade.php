@extends('layouts.base')
<div class="flex flex-col items-center justify-center h-full">
    <div>
        <p class="font-mono font-semibold text-7xl dark:text-warning">419</p>
        <h1 class="mt-4 font-bold tracking-tight dark:text-white">This page is definitely old, not like you!</h1>
        <p class="text-base leading-7 dark:text-neutral-300 text-black">Your session has expired. Please log in again to continue.
        </p>
        <details class="mt-6 text-sm dark:text-neutral-400 text-neutral-600">
            <summary class="cursor-pointer hover:dark:text-neutral-200 hover:text-neutral-800">Using a reverse proxy or Cloudflare Tunnel?</summary>
            <ul class="mt-2 ml-4 list-disc space-y-1">
                <li>Set your domain in <strong>Settings &rarr; FQDN</strong> to match the URL you use to access Coolify.</li>
                <li>Cloudflare users: disable <strong>Browser Integrity Check</strong> and <strong>Under Attack Mode</strong> for your Coolify domain, as these can interrupt login sessions.</li>
                <li>If you can still access Coolify via <code>localhost</code>, log in there first to configure your FQDN.</li>
            </ul>
        </details>
        <div class="flex items-center mt-6 gap-x-2">
            <a href="/login">
                <x-forms.button>Back to Login</x-forms.button>
            </a>
            <a target="_blank" class="text-xs" href="{{ config('constants.urls.contact') }}">Contact
                support
                <x-external-link />
            </a>
        </div>
    </div>
</div>
