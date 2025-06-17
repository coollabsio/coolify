{{ Illuminate\Mail\Markdown::parse('---') }}

Thank you,<br>
{{ config('app.name') ?? instanceSettings()->instance_name ?? 'Coolify' }}

@if(instanceSettings()->contact_support_enabled ?? false)
{{ Illuminate\Mail\Markdown::parse('[Contact Support](https://coolify.io/docs/contact)') }}
@endif
