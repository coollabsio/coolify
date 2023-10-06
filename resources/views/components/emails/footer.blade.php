{{ Illuminate\Mail\Markdown::parse('---') }}

Thank you,<br>
{{ config('app.name') ?? 'Coolify' }}

{{ Illuminate\Mail\Markdown::parse('[Contact Support](https://coolify.io/docs/contact)') }}
