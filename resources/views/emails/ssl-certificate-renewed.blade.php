<x-emails.layout>
<h2>{{ __('email.ssl_renewed.title') }}</h2>

<p>{{ __('email.ssl_renewed.body') }}</p>

<ul>
@foreach($resources as $resource)
    <li>{{ $resource->name }}</li>
@endforeach
</ul>

<div style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
    <strong>{{ __('email.ssl_renewed.action_required') }}</strong> {{ __('email.ssl_renewed.action_required_body') }}
</div>

<p>{{ __('email.ssl_renewed.validity') }}</p>

@if(isset($urls) && count($urls) > 0)
<div style="margin-top: 20px;">
    <p>{{ __('email.ssl_renewed.redeploy_links') }}</p>
    <ul>
    @foreach($urls as $name => $url)
        <li><a href="{{ $url }}">{{ $name }}</a></li>
    @endforeach
    </ul>
</div>
@endif
</x-emails.layout> 
