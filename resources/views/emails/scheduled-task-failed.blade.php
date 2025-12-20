<x-emails.layout>
{{ __('email.scheduled_task_failed.body', ['name' => $task->name]) }}

<pre>
{{ $output }}
</pre>

{{ __('email.scheduled_task_failed.action', ['url' => $url]) }}
</x-emails.layout>
