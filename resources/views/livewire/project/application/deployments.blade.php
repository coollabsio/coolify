 <div class="flex flex-col gap-2 pt-2" wire:init='loadDeployments' wire:poll.5000ms='reloadDeployments'>
     <div wire:loading wire:target='loadDeployments'>
         <x-loading />
     </div>
     @foreach ($deployments as $deployment)
         <a @class([
             'bg-coolgray-200 p-2 border-l border-dashed transition-colors hover:no-underline',
             'cursor-not-allowed hover:bg-coolgray-200' =>
                 $deployment->status === 'queued' ||
                 $deployment->status === 'cancelled by system',
             'border-warning hover:bg-warning hover:text-black' =>
                 $deployment->status === 'in_progress',
             'border-error hover:bg-error' => $deployment->status === 'error',
             'border-success hover:bg-success' => $deployment->status === 'finished',
         ]) @if ($deployment->status !== 'cancelled by system' && $deployment->status !== 'queued')
             href="{{ $current_url . '/' . $deployment->extra_attributes['deployment_uuid'] }}"
     @endif
     class="hover:no-underline">
     <div class="flex flex-col justify-start">
         <div>
             {{ $deployment->status }}
         </div>
         <div>
             {{ $deployment->created_at }}
         </div>
     </div>
     </a>
     @endforeach
 </div>
