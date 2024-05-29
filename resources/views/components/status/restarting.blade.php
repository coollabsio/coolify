@props([
    'status' => 'Restarting',
    'lastDeploymentInfo' => null,
    'lastDeploymentLink' => null,
])
<div class="flex items-center">
    <x-loading wire:loading.delay.longer />
    <span wire:loading.remove.delay.longer class="flex items-center">
        <div class="badge badge-warning "></div>
        <div class="pl-2 pr-1 text-xs font-bold tracking-wider dark:text-warning" @if($lastDeploymentInfo) title="{{$lastDeploymentInfo}}" @endif>
            @if ($lastDeploymentLink)
              <a href="{{ $lastDeploymentLink }}" target="_blank" class="underline cursor-pointer">
                  {{ str($status)->before(':')->headline() }}
              </a>
          @else
              {{ str($status)->before(':')->headline() }}
          @endif
        </div>
        @if (!str($status)->startsWith('Proxy') && !str($status)->contains('('))
            <div class="text-xs dark:text-warning">({{ str($status)->after(':') }})</div>
        @endif
    </span>
</div>
