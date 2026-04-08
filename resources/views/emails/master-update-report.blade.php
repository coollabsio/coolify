<x-emails.layout>
{{ $totalUpdates }} new update{{ $totalUpdates === 1 ? '' : 's' }} detected across your Coolify resources.

@if(!empty($sections['coolify_upgrades']))
**Coolify**
@foreach($sections['coolify_upgrades'] as $item)
@if(!empty($item['url']))
- [**{{ $item['label'] }}**]({{ $item['url'] }}): {{ $item['summary'] }}
@else
- **{{ $item['label'] }}**: {{ $item['summary'] }}
@endif
@endforeach

@endif
@if(!empty($sections['proxy_upgrades']))
**Proxy Upgrades**
@foreach($sections['proxy_upgrades'] as $item)
@if(!empty($item['url']))
- [**{{ $item['label'] }}**]({{ $item['url'] }}): {{ $item['summary'] }}
@else
- **{{ $item['label'] }}**: {{ $item['summary'] }}
@endif
@endforeach

@endif
@if(!empty($sections['server_patches']))
**Server Patches**
@foreach($sections['server_patches'] as $server)
@if(!empty($server['url']))
- [**{{ $server['label'] }}**]({{ $server['url'] }})
@else
- **{{ $server['label'] }}**
@endif
@foreach($server['packages'] as $package)
  - {{ $package['label'] }}: {{ $package['summary'] }}
@endforeach
@endforeach

@endif
@if(!empty($sections['container_image_updates']))
**Container Images**
@foreach($sections['container_image_updates'] as $item)
@if(!empty($item['url']))
- [**{{ $item['label'] }}**]({{ $item['url'] }}): {{ $item['summary'] }}
@else
- **{{ $item['label'] }}**: {{ $item['summary'] }}
@endif
@endforeach
@endif
</x-emails.layout>
