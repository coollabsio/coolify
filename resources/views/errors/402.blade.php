@extends('layouts.base')

@section('body')
    <body class="error-page-body text-black dark:text-inherit">
        <x-toast />
        <x-error-page
            code="402"
            title="Payment required"
            description="A valid subscription or payment is required to continue." />
    </body>
@endsection
