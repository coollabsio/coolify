<x-emails.layout>
{{ $title ?? "Scheduled task ({$task->name}) failed." }}

{{ $summary ?? '' }}

Last error:

<pre>
{{ $output }}
</pre>

Click [here]({{ $url }}) to view the task.
</x-emails.layout>
