<x-emails.layout>

{{ __('email.s3_connection_error.body', ['name' => $name, 'url' => $url]) }}

{{ $reason }}
</x-emails.layout>
