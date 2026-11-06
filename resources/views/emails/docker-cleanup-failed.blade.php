<x-emails.layout>
Docker Cleanup on {{ $name }} FAILED with the following error:

<pre>
{{ $text }}
</pre>

@isset($reminder)
{{ $reminder }}
@endisset

</x-emails.layout>
