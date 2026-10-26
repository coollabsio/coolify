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
@foreach ($resource['ids'] as $id)
- {{ $id }}
@endforeach
@if (count($resource['ids']) > 0 && $resource['more_ids'] > 0)
- And {{ $resource['more_ids'] }} more.
@endif
@foreach ($resource['updates'] as $update)
- {{ $update }}
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

See all results on the [Vulnerabilities page]({{ $url }}).
</x-emails.layout>
