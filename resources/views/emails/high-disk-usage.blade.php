<x-emails.layout>
{{ __('email.high_disk_usage.body', ['name' => $name, 'disk_usage' => $disk_usage, 'threshold' => $threshold]) }}

{{ __('email.high_disk_usage.action', ['url' => 'https://coolify.io/docs/knowledge-base/server/automated-cleanup']) }}

{{ __('email.high_disk_usage.settings') }}
</x-emails.layout>
