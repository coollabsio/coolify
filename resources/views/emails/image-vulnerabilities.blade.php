<x-emails.layout>
{{ $intro }}
@if (count($resources) > 0)

## Resources

@foreach ($resources as $resource)
@if ($resource['url'])
### [{{ $resource['name'] }}]({{ $resource['url'] }})
@else
### {{ $resource['name'] }}
@endif

@if ($resource['summary'] !== '')
{{ $resource['summary'] }}

@endif
@foreach ($resource['why'] as $why)
- {{ $why }}
@endforeach

@endforeach
@endif
@if (count($ignores) > 0)
## Ignores that end within 7 days

@foreach ($ignores as $ignore)
- {{ $ignore['subject'] }}{{ $ignore['resource'] ? ' on '.$ignore['resource'] : ' on all resources' }}, ends on {{ $ignore['expires_at'] }}
@endforeach

@endif
@if (count($servers) > 0)
## Servers whose scans failed for 2 days

@foreach ($servers as $server)
- [{{ $server['name'] }}]({{ $server['url'] }})
@endforeach

@endif
---

See all results on the [Security page]({{ $url }}).
</x-emails.layout>
