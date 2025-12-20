<x-emails.layout>
{{ __('email.scheduled_task_success.body', ['name' => $task->name]) }}

<pre>
{{ $output }}
</pre>

{{ __('email.scheduled_task_success.action', ['url' => $url]) }}
</x-emails.layout>
