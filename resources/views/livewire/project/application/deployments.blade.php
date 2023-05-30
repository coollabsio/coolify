 <div class="flex flex-col gap-2 pt-2" wire:init='loadDeployments' wire:poll.5000ms='reloadDeployments'>
     <div wire:loading wire:target='loadDeployments'>
         <x-loading />
     </div>
     @foreach ($deployments as $deployment)
         <a @class([
             'bg-coolgray-200 p-2 border-l border-dashed transition-colors hover:no-underline',
             'cursor-not-allowed hover:bg-coolgray-200' =>
                 data_get($deployment, 'status') === 'queued' ||
                 data_get($deployment, 'status') === 'cancelled by system',
             'border-warning hover:bg-warning hover:text-black' =>
                 data_get($deployment, 'status') === 'in_progress',
             'border-error hover:bg-error' =>
                 data_get($deployment, 'status') === 'error',
             'border-success hover:bg-success' =>
                 data_get($deployment, 'status') === 'finished',
         ]) @if (data_get($deployment, 'status') !== 'cancelled by system' && data_get($deployment, 'status') !== 'queued')
             href="{{ $current_url . '/' . data_get($deployment, 'deployment_uuid') }}"
     @endif
     class="hover:no-underline">
     <div class="flex flex-col justify-start">
         @if (data_get($deployment, 'pull_request_id'))
             <div>Pull Request #{{ data_get($deployment, 'pull_request_id') }}</div>
         @else
             <div>Commit:
                 @if (data_get($deployment, 'commit'))
                     {{ data_get($deployment, 'commit') }}
                 @else
                     HEAD
                 @endif
             </div>
         @endif
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
