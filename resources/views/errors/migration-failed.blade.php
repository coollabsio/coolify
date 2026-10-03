@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="503"
            title="The database migration failed."
            description="Coolify {{ $version }} is paused until it succeeds. Your applications keep running."
            tone="danger"
            :show-go-back="false"
            :show-dashboard="false">
            <div class="error-message">{{ $error }}</div>
            <p class="error-description">
                Run <code>docker logs coolify</code> on the server to see the full error. Fix the cause, for example by
                freeing up disk space, then run <code>docker restart coolify</code> to retry the migration.
            </p>
        </x-error-page>
    </body>
@endsection
