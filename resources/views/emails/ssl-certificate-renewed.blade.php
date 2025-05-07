<x-emails.layout>
<h2>SSL Certificates Renewed</h2>

<p>SSL certificates have been renewed for the following resources:</p>

<ul>
@foreach($resources as $resource)
    <li>{{ $resource->name }}</li>
@endforeach
</ul>

<div style="margin: 20px 0; padding: 15px; background-color: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
    <strong>⚠️ Action Required:</strong> These resources need to be redeployed manually for the new SSL certificates to take effect. Please do this in the next few days to ensure your database connections remain accessible.
</div>

<p>The old SSL certificates will remain valid for approximately 14 more days, as we renew certificates 14 days before their expiration.</p>

@if(isset($urls) && count($urls) > 0)
<div style="margin-top: 20px;">
    <p>You can redeploy these resources here:</p>
    <ul>
    @foreach($urls as $name => $url)
        <li><a href="{{ $url }}">{{ $name }}</a></li>
    @endforeach
    </ul>
</div>
@endif
</x-emails.layout> 
